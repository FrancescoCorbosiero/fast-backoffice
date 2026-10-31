<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Kernel\Reference;
use App\Modules\Files\Actions\StoreFile;
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
        private GetSdiSettings $settings,
        private SaveSdiSettings $saveSettings,
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

    private function classify(IncomingPecMessage $message, PecEnvelope $envelope): PecMessageData
    {
        if ($envelope->isTransportReceipt()) {
            return $this->transportReceipt($message, $envelope);
        }

        if ($envelope->isTransportError()) {
            $transmission = $envelope->referencedMessageId === null ? null : SdiTransmission::query()->where('message_id', $envelope->referencedMessageId)->first();
            $transmission?->forceFill(['transport_error' => mb_substr($envelope->certificationType.($envelope->certificationError !== null ? ': '.$envelope->certificationError : ''), 0, 500)])->save();

            return $this->store($message, $envelope, PecMessageKind::PecError, PecMessageOutcome::Review, 'Il gestore PEC segnala "'.$envelope->certificationType.'" per il messaggio '.($envelope->referencedMessageId ?? '—').'.', invoiceId: $transmission?->invoice_id);
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
            return $this->supplierInvoice($message, $envelope, $invoiceFile, $metadata);
        }

        if ($notifications !== []) {
            return $this->notification($message, $envelope, $notifications[0]);
        }

        return $this->store($message, $envelope, PecMessageKind::Unknown, PecMessageOutcome::Review, 'Nessun allegato riconosciuto: '.(implode(', ', array_map(fn (PecAttachment $a): string => $a->name, $envelope->attachments)) ?: 'nessuno').'.');
    }

    private function transportReceipt(IncomingPecMessage $message, PecEnvelope $envelope): PecMessageData
    {
        $kind = $envelope->certificationType === 'accettazione' ? PecMessageKind::PecAcceptance : PecMessageKind::PecDelivery;
        $transmission = $envelope->referencedMessageId === null ? null : SdiTransmission::query()->where('message_id', $envelope->referencedMessageId)->first();

        if ($transmission === null) {
            return $this->store($message, $envelope, $kind, PecMessageOutcome::Ignored, 'Ricevuta del gestore per un messaggio non in uscita da qui ('.($envelope->referencedMessageId ?? '—').').');
        }

        $at = $envelope->date ?? Date::now();
        if ($kind === PecMessageKind::PecAcceptance) {
            $transmission->forceFill(['accepted_at' => $transmission->accepted_at ?? $at])->save();
        } elseif ($envelope->certificationType === 'avvenuta-consegna') {
            $transmission->forceFill(['delivered_to_sdi_at' => $transmission->delivered_to_sdi_at ?? $at])->save();
        }

        return $this->store($message, $envelope, $kind, PecMessageOutcome::Processed, null, invoiceId: $transmission->invoice_id, fileName: $transmission->file_name);
    }

    private function notification(IncomingPecMessage $message, PecEnvelope $envelope, PecAttachment $attachment): PecMessageData
    {
        try {
            $notification = $this->notifications->read($attachment->contents);
        } catch (InvalidArgumentException $exception) {
            return $this->store($message, $envelope, PecMessageKind::SdiOther, PecMessageOutcome::Review, $exception->getMessage(), fileName: $attachment->name);
        }

        $this->learnSdiAddress($envelope);

        try {
            $invoice = $this->recordNotification->record($notification, $attachment->name, $attachment->contents);
        } catch (InvalidArgumentException $exception) {
            return $this->store($message, $envelope, $notification->kind, PecMessageOutcome::Review, $exception->getMessage(), sdiIdentifier: $notification->sdiIdentifier, fileName: $attachment->name);
        }

        $note = $notification->kind === PecMessageKind::SdiRejected ? implode("\n", $notification->errorLines()) : $notification->description;

        return $this->store($message, $envelope, $notification->kind, PecMessageOutcome::Processed, $note, invoiceId: $invoice->id, sdiIdentifier: $notification->sdiIdentifier, fileName: $attachment->name);
    }

    private function supplierInvoice(IncomingPecMessage $message, PecEnvelope $envelope, PecAttachment $file, ?PecAttachment $metadata): PecMessageData
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
            return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Review, $exception->getMessage(), sdiIdentifier: $sdiId, fileName: $file->name);
        }

        $this->learnSdiAddress($envelope);
        $result = $this->import->import(SdiFileNames::plain($file->name), $xml, $sdiId);
        $supplierInvoiceId = $result->reference !== null && $result->reference->is('supplier_invoice') ? (int) $result->reference->key : null;

        if ($result->status === 'failed') {
            return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Review, $result->reason, sdiIdentifier: $sdiId, fileName: $file->name);
        }

        return $this->store($message, $envelope, PecMessageKind::SupplierInvoice, PecMessageOutcome::Processed, $result->status === 'skipped' ? $result->reason : null, supplierInvoiceId: $supplierInvoiceId, sdiIdentifier: $sdiId, fileName: $file->name);
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
    ): PecMessageData {
        return $this->db->transaction(function () use ($message, $envelope, $kind, $outcome, $note, $invoiceId, $supplierInvoiceId, $sdiIdentifier, $fileName): PecMessageData {
            $record = PecMessage::query()->create([
                'message_id' => $envelope !== null ? $envelope->messageId : hash('sha256', $message->raw),
                'uid' => mb_substr($message->uid, 0, 40),
                'received_at' => $envelope?->date,
                'from_address' => $envelope?->from,
                'subject' => $envelope === null ? null : mb_substr($envelope->subject, 0, 500),
                'kind' => $kind,
                'outcome' => $outcome,
                'note' => $note,
                'invoice_id' => $invoiceId,
                'supplier_invoice_id' => $supplierInvoiceId,
                'sdi_identifier' => $sdiIdentifier,
                'file_name' => $fileName,
                'processed_at' => Date::now(),
            ]);

            $file = $this->files->store(Reference::to('pec_message', $record->id), 'eml', 'message-'.$record->id.'.eml', $message->raw, 'message/rfc822');
            $record->forceFill(['raw_file_id' => $file->id])->save();

            if ($outcome === PecMessageOutcome::Review || $outcome === PecMessageOutcome::Failed) {
                $this->events->dispatch(new PecMessageNeedsReview($record->id, $note ?? ''));
            }

            return $record->toData();
        });
    }
}
