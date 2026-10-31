<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditEntries;

use App\Modules\Audit\Filament\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Modules\Audit\Filament\Resources\AuditEntries\Tables\AuditEntriesTable;
use App\Modules\Audit\Models\AuditEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The audit trail, read-only: entries are written by use cases, never by hand.
 */
class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'voce di registro';

    protected static ?string $pluralModelLabel = 'Registro attività';

    public static function table(Table $table): Table
    {
        return AuditEntriesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEntries::route('/'),
        ];
    }
}
