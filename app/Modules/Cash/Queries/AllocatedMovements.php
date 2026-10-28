<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Models\Movement;
use App\Support\Row;
use Illuminate\Contracts\Config\Repository;

/**
 * The movements allocated to other modules' documents, for those modules:
 * an invoice's payments, a renewal's cost.
 */
final readonly class AllocatedMovements
{
    private string $currency;

    public function __construct(Repository $config)
    {
        $currency = $config->get('backoffice.currency');
        $this->currency = is_string($currency) ? $currency : 'EUR';
    }

    /**
     * @return list<MovementData> chronological
     */
    public function for(Reference $allocation): array
    {
        return array_values(Movement::query()
            ->where('allocation', $allocation->toString())
            ->orderBy('occurred_on')->orderBy('id')
            ->get()
            ->map(fn (Movement $movement): MovementData => $movement->toData())
            ->all());
    }

    public function exists(Reference $allocation): bool
    {
        return Movement::query()->where('allocation', $allocation->toString())->exists();
    }

    /**
     * The signed total allocated to each reference, in one query.
     *
     * @param  list<Reference>  $allocations
     * @return array<string, Money> reference string → in minus out
     */
    public function totals(array $allocations): array
    {
        $keys = array_map(fn (Reference $allocation): string => $allocation->toString(), $allocations);
        $totals = array_fill_keys($keys, Money::zero($this->currency));

        if ($keys === []) {
            return $totals;
        }

        $rows = Movement::query()->toBase()
            ->selectRaw("allocation, sum(case direction when 'in' then amount_cents else -amount_cents end) as total")
            ->whereIn('allocation', $keys)
            ->groupBy('allocation')
            ->get();

        foreach ($rows as $row) {
            $totals[Row::string($row, 'allocation')] = new Money(Row::int($row, 'total'), $this->currency);
        }

        return $totals;
    }

    public function total(Reference $allocation): Money
    {
        return $this->totals([$allocation])[$allocation->toString()];
    }

    /**
     * The movements where a contact is the counterparty, newest first.
     *
     * @return list<MovementData>
     */
    public function forContact(int $contactId, int $limit = 100): array
    {
        return array_values(Movement::query()
            ->where('contact_id', $contactId)
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Movement $movement): MovementData => $movement->toData())
            ->all());
    }
}
