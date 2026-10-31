<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Models\Movement;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves a movement from one document to another of the same module: a
 * pro forma's payment becomes the invoice's. The caller proves ownership
 * with the current allocation.
 */
final readonly class ReallocateMovement
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    public function reallocate(int $movementId, Reference $from, Reference $to): MovementData
    {
        return $this->db->transaction(function () use ($movementId, $from, $to): MovementData {
            $movement = Movement::query()->lockForUpdate()->findOrFail($movementId);
            UpdateMovement::assertOwnership($movement, $from);

            $movement->forceFill(['allocation' => $to->toString()])->save();
            $data = $movement->toData();

            $this->audit->record('cash.movement.reallocated', $data->reference(), ['from' => $from->toString(), 'to' => $to->toString()]);

            return $data;
        });
    }
}
