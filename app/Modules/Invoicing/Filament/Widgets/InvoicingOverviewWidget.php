<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Widgets;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\SupplierInvoiceData;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\PecMessages\PecMessageResource;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Queries\Invoices;
use App\Modules\Invoicing\Queries\PecMessages;
use App\Modules\Invoicing\Queries\SupplierInvoices;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What needs doing: invoices to send to SdI (12 days), money owed, overdue,
 * SdI outcomes to handle, suppliers to pay. Every figure opens the list it
 * counts, filtered the same way.
 */
class InvoicingOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $invoices = app(Invoices::class);
        $toSend = $invoices->toSend();
        $receivables = $invoices->receivables();
        $overdue = array_values(array_filter($receivables, fn (InvoiceData $invoice): bool => $invoice->paymentState($today) === PaymentState::Overdue));
        $rejected = $invoices->withStatus(InvoiceStatus::Rejected);
        $notDelivered = $invoices->withStatus(InvoiceStatus::NotDelivered);
        $review = app(PecMessages::class)->needingReview();
        $payables = app(SupplierInvoices::class)->payables();
        $minDays = null;
        foreach ($toSend as $invoice) {
            $days = $invoice->daysLeftToSend($today);
            if ($days !== null && ($minDays === null || $days < $minDays)) {
                $minDays = $days;
            }
        }

        return [
            Stat::make('Fatture da inviare a SdI', (string) count($toSend))
                ->description($minDays === null ? 'nessuna in attesa' : ($minDays < 0 ? 'in ritardo di '.abs($minDays).' giorni' : "la prima scade tra {$minDays} giorni"))
                ->color($minDays !== null && $minDays < 3 ? 'danger' : ($toSend === [] ? 'success' : 'warning'))
                ->url(self::statusUrl(InvoiceStatus::Issued)),
            Stat::make('Da incassare', Money::sum(array_map(fn (InvoiceData $i): Money => $i->outstanding(), $receivables))->format())
                ->description(count($receivables).' fatture aperte')
                ->url(InvoiceResource::paymentFilterUrl(InvoiceResource::FILTER_OUTSTANDING)),
            Stat::make('Scadute', Money::sum(array_map(fn (InvoiceData $i): Money => $i->outstanding(), $overdue))->format())
                ->description(count($overdue).' fatture oltre la scadenza')
                ->color($overdue === [] ? 'success' : 'danger')
                ->url(InvoiceResource::paymentFilterUrl(InvoiceResource::FILTER_OVERDUE)),
            Stat::make('Esiti SdI da gestire', (string) (count($rejected) + count($notDelivered) + count($review)))
                ->description(sprintf('%d scartate · %d non consegnate · %d messaggi PEC da verificare', count($rejected), count($notDelivered), count($review)))
                ->color($rejected === [] && $notDelivered === [] && $review === [] ? 'success' : 'danger')
                ->url(self::outcomesUrl($rejected !== [], $notDelivered !== [])),
            Stat::make('Da pagare ai fornitori', Money::sum(array_map(fn (SupplierInvoiceData $s): Money => $s->outstanding(), $payables))->format())
                ->description(count($payables).' fatture fornitori aperte')
                ->url(SupplierInvoiceResource::unpaidUrl()),
        ];
    }

    private static function statusUrl(InvoiceStatus $status): string
    {
        return InvoiceResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);
    }

    /**
     * Where the SdI outcomes are dealt with: the rejected invoices first,
     * then the undelivered ones, otherwise the PEC messages to check.
     */
    private static function outcomesUrl(bool $rejected, bool $notDelivered): string
    {
        return match (true) {
            $rejected => self::statusUrl(InvoiceStatus::Rejected),
            $notDelivered => self::statusUrl(InvoiceStatus::NotDelivered),
            default => PecMessageResource::getUrl('index', ['filters' => ['outcome' => ['value' => PecMessageOutcome::Review->value]]]),
        };
    }
}
