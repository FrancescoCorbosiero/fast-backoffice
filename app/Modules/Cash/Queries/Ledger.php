<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Kernel\Money\Money;
use App\Modules\Cash\Data\LedgerLine;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Movement;
use App\Modules\Contacts\Queries\FindContact;
use Carbon\CarbonImmutable;

/**
 * An account's statement: its movements in a period with the running
 * balance, in a fixed number of queries whatever the period holds.
 */
final readonly class Ledger
{
    public function __construct(private FindContact $contacts) {}

    /**
     * @return list<LedgerLine> chronological
     */
    public function statement(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = Account::query()->findOrFail($accountId);
        $balance = $this->balanceBefore($account, $from);

        $movements = Movement::query()
            ->with('category')
            ->where('account_id', $accountId)
            ->between($from, $to)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();

        $contactIds = [];
        foreach ($movements as $movement) {
            if ($movement->contact_id !== null) {
                $contactIds[$movement->contact_id] = $movement->contact_id;
            }
        }
        $contacts = $this->contacts->byIds(array_values($contactIds));

        $lines = [];
        foreach ($movements as $movement) {
            $balance = $balance->plus($movement->signedAmount());
            $lines[] = new LedgerLine(
                movement: $movement->toData(),
                balanceAfter: $balance,
                categoryName: $movement->category?->name,
                contactName: $movement->contact_id === null ? null : ($contacts[$movement->contact_id]->displayName ?? null),
            );
        }

        return $lines;
    }

    public function balanceBefore(Account $account, CarbonImmutable $date): Money
    {
        $sum = Movement::query()->toBase()
            ->where('account_id', $account->id)
            ->where('occurred_on', '<', $date->toDateString())
            ->selectRaw("coalesce(sum(case direction when 'in' then amount_cents else -amount_cents end), 0) as balance")
            ->value('balance');

        return $account->openingBalance()->plus(new Money(is_numeric($sum) ? (int) $sum : 0, $account->currency));
    }
}
