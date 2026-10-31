<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Kernel\Numbering\NumberSequence;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Data\Series;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class SaveNumberingSeries
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private NumberSequence $sequence,
        private GetNumberingSeries $current,
        private GetInvoiceDefaults $invoiceDefaults,
    ) {}

    /**
     * Saves the series. A series in use by the invoice defaults can't be
     * removed, and an opening number is locked once the app has issued a
     * number in that series and year: see lockedOpeningNumbers().
     */
    public function save(NumberingSeries $series): void
    {
        $defaults = $this->invoiceDefaults->get();

        foreach ([$defaults->invoiceSeries, $defaults->creditNoteSeries] as $code) {
            if ($series->find($code) === null) {
                throw new LogicException("Series \"{$code}\" is used by the invoice defaults and can't be removed.");
            }
        }

        foreach ($this->lockedOpeningNumbers($series) as $code => $years) {
            foreach ($years as $year) {
                throw new LogicException("Series \"{$code}\" has already issued numbers in {$year}: its opening number is locked.");
            }
        }

        $this->db->transaction(function () use ($series): void {
            Setting::write(GetNumberingSeries::KEY, $series->toArray());
            $this->audit->record('settings.numbering_series.saved', details: $series->toArray());
        });
    }

    /**
     * The (series, year) opening numbers that differ from what's stored
     * while numbers were already issued: those can't change any more.
     *
     * @return array<string, list<int>>
     */
    public function lockedOpeningNumbers(NumberingSeries $series): array
    {
        $stored = $this->current->get();
        $locked = [];

        foreach ($series->series as $candidate) {
            $existing = $stored->find($candidate->code);
            $years = array_unique([...array_keys($candidate->openingNumbers), ...array_keys($existing->openingNumbers ?? [])]);

            foreach ($years as $year) {
                if ($this->openingChanged($candidate, $existing, $year) && $this->sequence->last($candidate->code, $year) !== null) {
                    $locked[$candidate->code][] = $year;
                }
            }
        }

        return $locked;
    }

    private function openingChanged(Series $candidate, ?Series $existing, int $year): bool
    {
        return $candidate->openingNumberFor($year) !== ($existing?->openingNumberFor($year) ?? 1);
    }
}
