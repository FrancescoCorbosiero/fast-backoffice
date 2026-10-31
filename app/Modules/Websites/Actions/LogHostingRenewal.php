<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Websites\Events\HostingRenewed;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Log renewal" (design.md §5.5): records the payment for the renewal that
 * is due and rolls the date forward by the step rule. Idempotent per
 * (website, period): logging the same period again returns the movement.
 */
final readonly class LogHostingRenewal
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
    public function log(int $websiteId, ?CarbonImmutable $paidOn = null, ?int $accountId = null): MovementData
    {
        return $this->db->transaction(function () use ($websiteId, $paidOn, $accountId): MovementData {
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);
            $data = $website->toData();

            if (! $data->hasHostingRenewal() || $website->hosting_renews_on === null || $website->hostingCost() === null) {
                throw ValidationException::withMessages(['hosting_renews_on' => 'Il sito non ha un hosting a pagamento con una scadenza.']);
            }

            $renewsOn = $website->hosting_renews_on;
            $allocation = $website->hostingAllocationFor($renewsOn);
            $existing = $this->allocated->for($allocation);
            if ($existing !== []) {
                return $existing[0];
            }

            $accountId ??= $website->hosting_account_id;
            if ($accountId === null) {
                throw ValidationException::withMessages(['account_id' => 'Scegli il conto da cui è uscito il pagamento.']);
            }

            $movement = $this->movements->record(new MovementInput(
                accountId: $accountId,
                direction: Direction::Out,
                amount: $website->hostingCost(),
                occurredOn: $paidOn ?? $renewsOn,
                description: 'Hosting '.$website->name.' ('.$renewsOn->isoFormat('MMM YYYY').')',
                categoryId: $website->hosting_category_id,
                allocation: $allocation,
            ));

            $website->forceFill(['hosting_renews_on' => $website->hostingRenewalAfter($renewsOn)->toDateString()])->save();
            $this->events->dispatch(new HostingRenewed($website->id, $renewsOn, $movement->id));

            return $movement;
        });
    }
}
