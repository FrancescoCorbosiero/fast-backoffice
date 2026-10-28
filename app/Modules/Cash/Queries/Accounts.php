<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Kernel\Money\Money;
use App\Modules\Cash\Data\AccountData;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Movement;
use App\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final readonly class Accounts
{
    /**
     * Every account with its balance, in two queries however many there are.
     *
     * @return list<AccountData>
     */
    public function all(bool $activeOnly = false, ?CarbonImmutable $asOf = null): array
    {
        $accounts = Account::query()->when($activeOnly, fn ($query) => $query->active())->orderBy('sort')->orderBy('name')->get();
        $sums = $this->signedSumsByAccount($asOf);

        return array_values($accounts->map(
            fn (Account $account): AccountData => $account->toData($account->openingBalance()->plus(new Money($sums[$account->id] ?? 0, $account->currency))),
        )->all());
    }

    public function find(int $id, ?CarbonImmutable $asOf = null): ?AccountData
    {
        $account = Account::query()->find($id);

        if ($account === null) {
            return null;
        }

        $sums = $this->signedSumsByAccount($asOf, $account->id);

        return $account->toData($account->openingBalance()->plus(new Money($sums[$account->id] ?? 0, $account->currency)));
    }

    /**
     * @return array<int, string> id → name, for selects
     */
    public function options(bool $activeOnly = true): array
    {
        $options = [];
        foreach (Account::query()->when($activeOnly, fn ($query) => $query->active())->orderBy('sort')->orderBy('name')->get(['id', 'name']) as $account) {
            $options[$account->id] = $account->name;
        }

        return $options;
    }

    /**
     * @return array<int, int> account id → sum of signed amounts in cents
     */
    private function signedSumsByAccount(?CarbonImmutable $asOf, ?int $accountId = null): array
    {
        $rows = Movement::query()->toBase()
            ->selectRaw("account_id, sum(case direction when 'in' then amount_cents else -amount_cents end) as balance")
            ->when($asOf !== null, fn (Builder $query) => $query->where('occurred_on', '<=', $asOf?->toDateString()))
            ->when($accountId !== null, fn (Builder $query) => $query->where('account_id', $accountId))
            ->groupBy('account_id')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $sums[Row::int($row, 'account_id')] = Row::int($row, 'balance');
        }

        return $sums;
    }
}
