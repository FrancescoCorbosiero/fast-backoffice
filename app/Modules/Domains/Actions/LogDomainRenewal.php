<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Domains\Events\DomainRenewed;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Log renewal" (design.md §5.5): records the payment for the period that
 * ends on the current expiry and moves the expiry forward by the step
 * rule. Idempotent per (domain, expiry): logging twice returns the movement.
 */
final readonly class LogDomainRenewal
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordMovement $movements,
        private AllocatedMovements $allocated,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function log(int $domainId, ?CarbonImmutable $paidOn = null, ?int $accountId = null): MovementData
    {
        return $this->db->transaction(function () use ($domainId, $paidOn, $accountId): MovementData {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domainId);
            $cost = $domain->renewalCost();

            if ($cost === null || ! $cost->isPositive()) {
                throw ValidationException::withMessages(['renewal_cost' => 'Il dominio non ha un costo di rinnovo.']);
            }

            $expiresOn = $domain->expires_on;
            $allocation = $domain->allocationFor($expiresOn);
            $existing = $this->allocated->for($allocation);
            if ($existing !== []) {
                return $existing[0];
            }

            $accountId ??= $domain->account_id;
            if ($accountId === null) {
                throw ValidationException::withMessages(['account_id' => 'Scegli il conto da cui è uscito il pagamento.']);
            }

            $movement = $this->movements->record(new MovementInput(
                accountId: $accountId,
                direction: Direction::Out,
                amount: $cost,
                occurredOn: $paidOn ?? $expiresOn,
                description: 'Rinnovo dominio '.$domain->name.' ('.$expiresOn->isoFormat('MMM YYYY').')',
                categoryId: $domain->category_id,
                allocation: $allocation,
            ));

            $domain->forceFill(['expires_on' => $domain->expiryAfter($expiresOn)->toDateString()])->save();
            $this->events->dispatch(new DomainRenewed($domain->id, $expiresOn, $domain->expires_on, $movement->id));

            return $movement;
        });
    }
}
