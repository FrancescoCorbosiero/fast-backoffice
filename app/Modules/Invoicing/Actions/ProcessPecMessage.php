<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Data\PecMessageData;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Events\PecMessageNeedsReview;
use App\Modules\Invoicing\Integrations\FatturaPa\SignedFile;
use App\Modules\Invoicing\Integrations\Pec\IncomingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecAttachment;
use App\Modules\Invoicing\Integrations\Pec\PecEnvelope;
use App\Modules\Invoicing\Integrations\Pec\PecEnvelopeParser;
use App\Modules\Invoicing\Integrations\Pec\SdiFileNames;
use App\Modules\Invoicing\Integrations\Pec\SdiNotificationReader;
use App\Modules\Invoicing\Models\PecMessage;
use App\Modules\Invoicing\Models\SdiTransmission;
use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Queries\GetSdiSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * One PEC message, exactly once (sdi.md §5): the provider's receipts land
 * on the outbox entry; SdI's notifications on the invoice; a supplier
 * invoice becomes a payable; anything else is kept and flagged for review.
 * The raw message is always stored.
 */
final readonly class ProcessPecMessage
{
    public function __construct(
        private ConnectionInterface $db,
        private PecEnvelopeParser $parser,
        private SdiNotificationReader $notifications,
        private RecordSdiNotification $recordNotification,
        private ImportFatturaPa $import,
        private StoreFile $files,
        private ReadFile $readFile,
        private GetSdiSettings $settings,
        private SaveSdiSettings $saveSettings,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function process(IncomingPecMessage $message): PecMessageData
    {
        try {
            $envelope = $this->parser->parse($message->raw);
        } catch (Throwable $exception) {
            return $this->store($message, null, PecMessageKind::Unknown, PecMessageOutcome::Failed, 'Messaggio illeggibile: '.$exception->getMessage());
        }

        $seen = PecMessage::query()->where('message_id', $envelope->messageId)->first();
        if ($seen !== null) {
            return $seen->toData();
        }

        try {
            return $this->classify($message, $envelope);
        } catch (Throwable $exception) {
            report($exception);

            return $this->store($message, $envelope, PecMessageKind::Unknown, PecMessageOutcome::Failed, $exception->getMessage());
        }
    }

    /**
     * Runs a message flagged for review or failed through the pipeline
     * again, on the owner's request, once the cause is fixed (the VAT
     * number set, the invoice's outbox entry present). The record keeps
     * its id and raw file; only what the app did about it changes.
     *
     * @throws LogicException when the message is not one to rework
     */
    public function reprocess(int $pecMessageId): PecMessageData
    {
        $record = PecMessage::query()->findOrFail($pecMessageId);

        if (! in_array($record->outcome, [PecMessageOutcome::Review, PecMessageOutcome::Failed], true)) {
            throw new LogicException('Si rielaborano solo i messaggi da verificare o in errore.');
        }
        if ($record->raw_file_id === null) {
            throw new LogicException('Il messaggio originale non è stato conservato: non si può rielaborare.');
        }

        $message = new IncomingPecMessage($record->uid ?? (string) $record->id, $this->readFile->contents($record->raw_file_id));
        $before = $record->outcome;

        try {
            $envelope = $this->parser->parse($message->raw);
            $result = $this->classify($message, $envelope, $record);
        } catch (Throwable $exception) {
            report($exception);
            $result = $this->store($message, null, PecMessageKind::Unknown, PecMessageOutcome::Failed, 'Messaggio illeggibile: '.$exception->getMessage(), existing: $record);
        }

        $this->audit->record('invoicing.pec_message.reprocessed', $result->reference(), ['from' => $before->value, 'to' => $result->outcome->value]);

        return $result;
    }

    private function classify(IncomingPecMessage $message, PecEnvelope $envelope, ?PecMessage $existing = null): PecMessageData
    {
        if ($envelope->isTransportReceipt()) {
            return $this->transportReceipt($message, $envelope, $existing);
        }

        if ($envelope->isTransportError()) {
            $transmission = $envelope->referencedMessageId === null ? null : SdiTransmission::query()->where('message_id', $envelope->referencedMessageId)->first();
            $transmission?->forceFill(['transport_error' => mb_substr($envelope->certificationType.($envelope->certificationError !== null ? ': '.$envelope->certificationError : ''), 0, 500)])->save();

            return $this->store($message, $envelope, PecMessageKind::PecError, PecMessageOutcome::Review, 'Il gestore PEC segnala "'.$envelope->certificationType.'" per il messaggio '.($envelope->referencedMessageId ?? '—').'.', invoiceId: $transmission?->invoice_id, existing: $existing);
        }

        $notifications = [];
        $metadata = null;
        $invoiceFile = null;
        foreach ($envelope->attachments as $attachment) {
            $parsed = SdiFileNames::notification($attachment->name);
            if ($parsed !== null && $parsed['code'] === 'MT') {
                $metadata = $attachment;
            } elseif ($parsed !== null) {
                $notifications[] = $attachment;
            } elseif (SdiFileNames::isInvoice($attachment->name)) {
                $invoiceFile = $attachment;
            }
        }

        if ($invoiceFile !== null) {
            return $this->supplierInvoice($message, $envelope, $invoiceFile, $metadata, $existing);
        }

        if ($notifications !== []) {
            return $this->notification($message, $envelope, $notifications[0], $existing);
        }

        return $this->store($message, $envelope, PecMessageKind::Unknown, PecMessageOutcome::Review, 'Nessun allegato riconosciuto: '.(implode(', ', array_map(fn (PecAttachment $a): string => $a->name, $envelope->attachments)) ?: 'nessuno').'.', existing: $existing);
    }

    private function transportReceipt(IncomingPecMessage $message, PecEnvelope $envelope, ?PecMessage $existing = null): PecMessageData
    {
        $kind = $envelope->certificationType === 'accettazione' ? PecMessageKind::PecAcceptance : PecMessageKind::PecDelivery;
        $transmission = $envelope->referencedMessageId === null ? null : SdiTransmission::query()->where('message_id', $envelope->referencedMessageId)->first();

        if ($transmission === null) {
            return $this->store($message, $envelope, $kind, PecMessageOutcome::Ignored, 'Ricevuta del gestore per un messaggio non in uscita da qui ('.($envelope->referencedMessageId ?? '—').').', existing: $existing);
        }

        $at = $envelope->date ?? Date::now();
        if ($kind === PecMessageKind::PecAcceptance) {
            $transmission->forceFill(['accepted_at' => $transmission->accepted_at ?? $at])->save();
        } elseif ($envelope->certificationType === 'avvenuta-consegna') {
            $transmission->forceFill(['delivered_to_sdi_at' => $transmission->delivered_to_sdi_at ?? $at])->save();
        }

        return $this->store($message, $envelope, $kind, PecMessageOutcome::Processed, null, invoiceId: $transmission->invoice_id, fileName: $transmission->file_name, existing: $existing);
    }

    private function notification(IncomingPecMessage $message, PecEnvelope $envelope, PecAttachment $attachment, ?PecMessage $existing = null): PecMessageData
    {
        try {
            $notification = $this->notifications->read($attachment->contents);
        } catch (InvalidArgumentException $exception) {
            return $this->store($message, $envelope, PecMessageKind::SdiOther, PecMessageOutcome::Review, $exception->getMessage(), fileName: $attachment->name, existing: $existing);
        }

        $this->learnSdiAddress($envelope);

        try {
            $invoice = $this->recordNotification->record($notification, $attachment->name, $attachment->contents);
        } catch (InvalidArgumentException $exception) {
            return $this->store($message, $envelope, $notification->kind, PecMessageOutcome::Review, $exception->getMessage(), sdiIdentifier: $notification->sdiIdentifier, fileName: $attachment->name, existing: $existing);
        }

        $note = $notification->kind === PecMessageKind::SdiRejected ? implode("\n", $notification->errorLines()) : $notification->description;

        return $this->store($message, $envelope, $notification->kind, PecMessageOutcome::Processed, $note, invoiceId: $invoice->id, sdiIdentifier: $notification->sdiIdentifier, fileName: $attachment->name, existing: $existing);
    }

    private function supplierInvoice(IncomingPecMessage $message, PecEnvelope $envelope, PecAttachment $file, ?PecAttachment $metadata, ?PecMessage $existing = null): PecMessageData
    {
        $sdiId = null;
        if ($metadata !== null) {
            try {
                $sdiId = $this->notifications->read($metadata->contents)->sdiIdentifier;
            } catch (InvalidArgumentException) {
                $sdiId = null;
            }
        }

        try {
            $xml = SignedFile::isSigned($file->name) ? SignedFile::unwrap($file->contents) : $file->contents;
        } catch (InvalidArgumentException $exception) {
            return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Review, $exception->getMessage(), sdiIdentifier: $sdiId, fileName: $file->name, existing: $existing);
        }

        $this->learnSdiAddress($envelope);
        $result = $this->import->import(SdiFileNames::plain($file->name), $xml, $sdiId);
        $supplierInvoiceId = $result->reference !== null && $result->reference->is('supplier_invoice') ? (int) $result->reference->key : null;

        if ($result->status === 'failed') {
            return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Review, $result->reason, sdiIdentifier: $sdiId, fileName: $file->name, existing: $existing);
        }

        return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Processed, $result->status === 'skipped' ? $result->reason : null, supplierInvoiceId: $supplierInvoiceId, sdiIdentifier: $sdiId, fileName: $file->name, existing: $existing);
    }

    /**
     * SdI answers from the address to use for every later file (sdi.md §2).
     */
    private function learnSdiAddress(PecEnvelope $envelope): void
    {
        $settings = $this->settings->get();
        $sender = $envelope->originalFrom;
        if ($sender === null || ! $settings->awaitsSdiReplyAddress() || $sender === $settings->sdiAddress) {
            return;
        }
        if (! str_ends_with($sender, '@pec.fatturapa.it')) {
            return;
        }

        $this->saveSettings->save($settings->withSdiAddress($sender));
    }

    private function store(
        IncomingPecMessage $message,
        ?PecEnvelope $envelope,
        PecMessageKind $kind,
        PecMessageOutcome $outcome,
        ?string $note,
        ?int $invoiceId = null,
        ?int $supplierInvoiceId = null,
        ?string $sdiIdentifier = null,
        ?string $fileName = null,
        ?PecMessage $existing = null,
    ): PecMessageData {
        return $this->db->transaction(function () use ($message, $envelope, $kind, $outcome, $note, $invoiceId, $supplierInvoiceId, $sdiIdentifier, $fileName, $existing): PecMessageData {
            $handled = [
                'kind' => $kind,
                'outcome' => $outcome,
                'note' => $note,
                'invoice_id' => $invoiceId,
                'supplier_invoice_id' => $supplierInvoiceId,
                'sdi_identifier' => $sdiIdentifier,
                'file_name' => $fileName,
                'processed_at' => Date::now(),
            ];

            if ($existing !== null) {
                // A rework: the message, its id and its raw file are the ones already on record.
                $existing->forceFill($handled)->save();
                $record = $existing;
            } else {
                $record = PecMessage::query()->create([
                    'message_id' => $envelope !== null ? $envelope->messageId : hash('sha256', $message->raw),
                    'uid' => mb_substr($message->uid, 0, 40),
                    'received_at' => $envelope?->date,
                    'from_address' => $envelope?->from,
                    'subject' => $envelope === null ? null : mb_substr($envelope->subject, 0, 500),
                    ...$handled,
                ]);

                $file = $this->files->store(Reference::to('pec_message', $record->id), 'eml', 'message-'.$record->id.'.eml', $message->raw, 'message/rfc822');
                $record->forceFill(['raw_file_id' => $file->id])->save();
            }

            if ($outcome === PecMessageOutcome::Review || $outcome === PecMessageOutcome::Failed) {
                $this->events->dispatch(new PecMessageNeedsReview($record->id, $note ?? ''));
            }

            return $record->toData();
        });
    }
}
