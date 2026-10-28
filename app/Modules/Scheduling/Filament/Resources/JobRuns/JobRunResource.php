<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns;

use App\Modules\Scheduling\Filament\Resources\JobRuns\Pages\ListJobRuns;
use App\Modules\Scheduling\Filament\Resources\JobRuns\Pages\ViewJobRun;
use App\Modules\Scheduling\Filament\Resources\JobRuns\Schemas\JobRunInfolist;
use App\Modules\Scheduling\Filament\Resources\JobRuns\Tables\JobRunsTable;
use App\Modules\Scheduling\Models\JobRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Run history, read-only.
 */
class JobRunResource extends Resource
{
    protected static ?string $model = JobRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'esecuzione';

    protected static ?string $pluralModelLabel = 'Esecuzioni';

    protected static ?string $slug = 'system/job-runs';

    public static function infolist(Schema $schema): Schema
    {
        return JobRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobRunsTable::configure($table);
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
            'index' => ListJobRuns::route('/'),
            'view' => ViewJobRun::route('/{record}'),
        ];
    }
}
