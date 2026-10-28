<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Models\Movement;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Changes a movement. An allocated movement belongs to the module that
 * allocated it: only that module may change it, and it proves it by
 * passing the allocation.
 */
final readonly class UpdateMovement
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function update(int $movementId, MovementInput $input, ?Reference $allocation = null): MovementData
    {
        return $this->db->transaction(function () use ($movementId, $input, $allocation): MovementData {
            $movement = Movement::query()->lockForUpdate()->findOrFail($movementId);
            self::assertOwnership($movement, $allocation);

            $input = $input->withAllocation($allocation);
            RecordMovement::validate($input);

            $before = $movement->toData();
            $movement->fill(RecordMovement::attributes($input));
            $changed = array_keys($movement->getDirty());
            $movement->save();
            $data = $movement->toData();

            if ($changed !== []) {
                $this->audit->record('cash.movement.updated', $data->reference(), [
                    'changed' => $changed,
                    'amount_before' => $before->signedAmount()->toDecimalString(),
                    'amount_after' => $data->signedAmount()->toDecimalString(),
                ]);
            }

            return $data;
        });
    }

    public static function assertOwnership(Movement $movement, ?Reference $allocation): void
    {
        if ($movement->allocation === null && $allocation === null) {
            return;
        }

        if ($movement->allocation !== null && $allocation !== null && $movement->allocation === $allocation->toString()) {
            return;
        }

        throw new LogicException($movement->allocation === null
            ? "Movement {$movement->id} is not allocated."
            : "Movement {$movement->id} belongs to {$movement->allocation}: change it through that document.");
    }
}
