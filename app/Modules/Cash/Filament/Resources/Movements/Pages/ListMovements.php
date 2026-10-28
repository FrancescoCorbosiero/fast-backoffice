<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements\Pages;

use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Models\Movement;
use App\Modules\Contacts\Queries\FindContact;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListMovements extends ListRecords
{
    protected static string $resource = MovementResource::class;

    /** @var array<int, string>|null */
    private ?array $contactNames = null;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * The counterparties of the page's rows, looked up once through the
     * Contacts module rather than once per row.
     */
    public function contactName(?int $contactId): ?string
    {
        if ($contactId === null) {
            return null;
        }

        if ($this->contactNames === null) {
            $records = $this->getTableRecords();
            $items = $records instanceof Collection ? $records->all() : $records->items();
            $ids = [];

            foreach ($items as $record) {
                if ($record instanceof Movement && $record->contact_id !== null) {
                    $ids[$record->contact_id] = $record->contact_id;
                }
            }

            $this->contactNames = array_map(fn ($contact): string => $contact->displayName, app(FindContact::class)->byIds(array_values($ids)));
        }

        return $this->contactNames[$contactId] ?? null;
    }
}
