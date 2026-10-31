<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Widgets;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Queries\Invoices;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What needs doing: invoices to send to SdI (12 days), money owed, overdue.
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
                ->color($minDays !== null && $minDays < 3 ? 'danger' : ($toSend === [] ? 'success' : 'warning')),
            Stat::make('Da incassare', Money::sum(array_map(fn (InvoiceData $i): Money => $i->outstanding(), $receivables))->format())
                ->description(count($receivables).' fatture aperte'),
            Stat::make('Scadute', Money::sum(array_map(fn (InvoiceData $i): Money => $i->outstanding(), $overdue))->format())
                ->description(count($overdue).' fatture oltre la scadenza')
                ->color($overdue === [] ? 'success' : 'danger'),
        ];
    }
}
