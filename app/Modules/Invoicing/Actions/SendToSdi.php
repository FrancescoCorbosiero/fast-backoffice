<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\SendChannel;
use App\Modules\Invoicing\Events\InvoiceSent;
use App\Modules\Invoicing\Integrations\Pec\OutgoingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\SdiTransmission;
use App\Modules\Settings\Queries\GetSdiSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * One PEC message per file to SdI (sdi.md §3), recorded in the outbox.
 * The invoice moves to Sent; SdI's answer comes back through the inbox.
 */
final readonly class SendToSdi
{
    public function __construct(
        private ConnectionInterface $db,
        private PecMailbox $mailbox,
        private GetSdiSettings $settings,
        private ReadFile $files,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * The channel is usable: mailbox configured and the app's PEC address set.
     */
    public function isAvailable(): bool
    {
        return $this->mailbox->isConfigured() && $this->settings->get()->pecAddress !== null;
    }

    /**
     * @throws ValidationException when the channel is not set up
     * @throws LogicException when the invoice is not waiting to be sent
     * @throws RuntimeException when the message doesn't leave
     */
    public function send(int $invoiceId): InvoiceData
    {
        $settings = $this->settings->get();
        if (! $this->mailbox->isConfigured() || $settings->pecAddress === null) {
            throw ValidationException::withMessages(['pec' => 'La casella PEC non è configurata: esporta l\'XML e caricalo sul portale, oppure completa le impostazioni SdI.']);
        }

        $invoice = Invoice::query()->findOrFail($invoiceId);
        if (! $invoice->status->canBeSent()) {
            throw new LogicException("Invoice {$invoiceId} is {$invoice->status->value}: only an issued, unsent invoice goes to SdI.");
        }

        $fileName = $invoice->sdi_file_name ?? throw new LogicException("Invoice {$invoiceId} has no file name.");
        $xml = $this->files->contents($invoice->xml_file_id ?? throw new LogicException("Invoice {$invoiceId} has no XML."));

        $messageId = $this->mailbox->send(new OutgoingPecMessage(
            from: $settings->pecAddress,
            to: $settings->sdiAddress,
            subject: $fileName,
            text: 'In allegato il file '.$fileName.' per il Sistema di Interscambio.',
            attachmentName: $fileName,
            attachmentContents: $xml,
        ));

        return $this->db->transaction(function () use ($invoice, $fileName, $messageId, $settings): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $now = Date::now();

            SdiTransmission::query()->create([
                'invoice_id' => $invoice->id,
                'file_name' => $fileName,
                'message_id' => $messageId,
                'sent_to' => $settings->sdiAddress,
                'sent_at' => $now,
            ]);

            $invoice->forceFill(['status' => InvoiceStatus::Sent, 'sent_at' => $now, 'sent_channel' => SendChannel::Pec])->save();

            $data = $invoice->toData();
            $this->audit->record('invoicing.invoice.sent', $data->reference(), ['channel' => SendChannel::Pec->value, 'number' => $invoice->number_text, 'file' => $fileName, 'message_id' => $messageId, 'to' => $settings->sdiAddress]);
            $this->events->dispatch(new InvoiceSent($invoice->id, SendChannel::Pec));

            return $data;
        });
    }
}
