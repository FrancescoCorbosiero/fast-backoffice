<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Kernel\Money\Money;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * A giroconto: money moved from one of the owner's accounts to another.
 * Two movements in one transaction, out of the first and into the second,
 * neither counting in the analytics (design.md §4: a transfer stays in the
 * balances, not in the revenue or costs). Each stays a plain movement the
 * owner can still correct, since no document owns them.
 */
final readonly class TransferBetweenAccounts
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordMovement $recordMovement,
    ) {}

    /**
     * @return array{MovementData, MovementData} out of $fromAccountId, into $toAccountId
     *
     * @throws ValidationException
     */
    public function transfer(int $fromAccountId, int $toAccountId, Money $amount, CarbonImmutable $on, ?string $description = null): array
    {
        if ($fromAccountId === $toAccountId) {
            throw ValidationException::withMessages(['to_account_id' => 'Scegli due conti diversi.']);
        }

        $from = Account::query()->find($fromAccountId);
        $to = Account::query()->find($toAccountId);

        if ($from === null || ! $from->is_active) {
            throw ValidationException::withMessages(['from_account_id' => 'Scegli un conto attivo.']);
        }

        if ($to === null || ! $to->is_active) {
            throw ValidationException::withMessages(['to_account_id' => 'Scegli un conto attivo.']);
        }

        $description = trim((string) $description);
        $description = $description === '' ? "Giroconto {$from->name} → {$to->name}" : $description;

        return $this->db->transaction(fn (): array => [
            $this->recordMovement->record(new MovementInput($from->id, Direction::Out, $amount, $on, $description, isTaxable: false)),
            $this->recordMovement->record(new MovementInput($to->id, Direction::In, $amount, $on, $description, isTaxable: false)),
        ]);
    }
}
