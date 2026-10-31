<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Invoicing\Data\SdiNotification;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Events\InvoiceDelivered;
use App\Modules\Invoicing\Events\InvoiceNotDelivered;
use App\Modules\Invoicing\Events\InvoiceRejected;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\SdiTransmission;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Applies one SdI notification to the invoice it names (sdi.md §5):
 * RC → Delivered, NS → Rejected (and the 5-day countdown starts),
 * MC → Not delivered, AT → stays not delivered, on record.
 * Idempotent: a notification seen twice changes nothing.
 */
final readonly class RecordSdiNotification
{
    public function __construct(
        private ConnectionInterface $db,
        private StoreFile $files,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @return Invoice the invoice the notification was applied to
     *
     * @throws InvalidArgumentException when no invoice matches the notification
     */
    public function record(SdiNotification $notification, string $fileName, string $xml): Invoice
    {
        return $this->db->transaction(function () use ($notification, $fileName, $xml): Invoice {
            $invoice = $this->match($notification);
            $reference = $invoice->toData()->reference();
            $file = $this->files->store($reference, 'sdi_notification', $fileName, $xml, 'application/xml');
            $at = $notification->deliveredAt ?? $notification->receivedAt ?? Date::now();
            $code = substr($notification->kind->value, 4);

            $transmission = SdiTransmission::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->first();
            if ($transmission !== null && $notification->kind !== PecMessageKind::SdiAttestation) {
                $transmission->forceFill(['outcome' => strtoupper($code), 'outcome_at' => $at, 'sdi_identifier' => $notification->sdiIdentifier])->save();
            }

            $details = ['file' => $fileName, 'sdi_id' => $notification->sdiIdentifier, 'stored_file_id' => $file->id];

            switch ($notification->kind) {
                case PecMessageKind::SdiDelivered:
                    if ($invoice->status->hasReachedSdi() && $invoice->status !== InvoiceStatus::Delivered) {
                        $invoice->forceFill(['status' => InvoiceStatus::Delivered, 'sdi_identifier' => $notification->sdiIdentifier, 'sdi_outcome_at' => $at])->save();
                        $this->audit->record('invoicing.invoice.delivered', $reference, $details);
                        $this->events->dispatch(new InvoiceDelivered($invoice->id, $notification->sdiIdentifier));
                    }
                    break;

                case PecMessageKind::SdiRejected:
                    if ($invoice->status->hasReachedSdi()) {
                        $invoice->forceFill(['status' => InvoiceStatus::Rejected, 'sdi_identifier' => $notification->sdiIdentifier, 'sdi_outcome_at' => $at, 'sdi_errors' => $notification->errors, 'rejected_at' => $at])->save();
                        $this->audit->record('invoicing.invoice.rejected', $reference, [...$details, 'errors' => $notification->errorLines()]);
                        $this->events->dispatch(new InvoiceRejected($invoice->id, $notification->errors));
                    }
                    break;

                case PecMessageKind::SdiNotDelivered:
                    if ($invoice->status->hasReachedSdi() && $invoice->status !== InvoiceStatus::NotDelivered) {
                        $invoice->forceFill(['status' => InvoiceStatus::NotDelivered, 'sdi_identifier' => $notification->sdiIdentifier, 'sdi_outcome_at' => $at])->save();
                        $this->audit->record('invoicing.invoice.not_delivered', $reference, [...$details, 'description' => $notification->description]);
                        $this->events->dispatch(new InvoiceNotDelivered($invoice->id, $notification->sdiIdentifier));
                    }
                    break;

                default:
                    $this->audit->record('invoicing.invoice.sdi_notification', $reference, [...$details, 'type' => $notification->rootElement]);
            }

            return $invoice;
        });
    }

    /**
     * By file name first, then by SdI's identifier (sdi.md §5).
     *
     * @throws InvalidArgumentException
     */
    private function match(SdiNotification $notification): Invoice
    {
        $invoice = null;
        if ($notification->fileName !== null) {
            $invoice = Invoice::query()->lockForUpdate()->where('sdi_file_name', $notification->fileName)->first();
        }
        if ($invoice === null && $notification->sdiIdentifier !== null) {
            $invoice = Invoice::query()->lockForUpdate()->where('sdi_identifier', $notification->sdiIdentifier)->first();
        }

        return $invoice ?? throw new InvalidArgumentException(sprintf('Nessuna fattura corrisponde alla notifica %s (file %s, id SdI %s).', $notification->rootElement, $notification->fileName ?? '—', $notification->sdiIdentifier ?? '—'));
    }
}
