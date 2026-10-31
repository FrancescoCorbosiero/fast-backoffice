<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Events\MovementRemoved;
use App\Modules\Cash\Models\Movement;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes a movement. As with UpdateMovement, an allocated movement is
 * removed only by the module that allocated it.
 */
final readonly class DeleteMovement
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function delete(int $movementId, ?Reference $allocation = null): void
    {
        $this->db->transaction(function () use ($movementId, $allocation): void {
            $movement = Movement::query()->lockForUpdate()->find($movementId);

            if ($movement === null) {
                return;
            }

            UpdateMovement::assertOwnership($movement, $allocation);
            $data = $movement->toData();
            $movement->delete();

            $this->audit->record('cash.movement.deleted', $data->reference(), [
                'amount' => $data->signedAmount()->toDecimalString(),
                'occurred_on' => $data->occurredOn->toDateString(),
                'description' => $data->description,
                'allocation' => $data->allocation?->toString(),
            ]);
            $this->events->dispatch(new MovementRemoved($data->id, $data->allocation?->toString()));
        });
    }
}
