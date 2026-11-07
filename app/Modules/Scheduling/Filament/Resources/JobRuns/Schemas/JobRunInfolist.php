<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns\Schemas;

use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Queries\ListJobs;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $labels = app(ListJobs::class)->labels();

        return $schema->components([
            Section::make()->columns(4)->components([
                TextEntry::make('job_key')->label('Operazione')
                    ->formatStateUsing(fn (string $state): string => $labels[$state] ?? $state)
                    ->helperText(fn (string $state): string => $state),
                TextEntry::make('trigger')->label('Avvio')->formatStateUsing(fn (RunTrigger $state): string => $state->label()),
                TextEntry::make('started_at')->label('Inizio')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('finished_at')->label('Fine')->dateTime('d/m/Y H:i:s')->placeholder('in corso'),
                TextEntry::make('exit_code')->label('Codice di uscita')->placeholder('—'),
            ]),
            Section::make('Output')->components([
                TextEntry::make('output')->label('')->fontFamily('mono')->placeholder('(nessun output)')->columnSpanFull(),
            ]),
        ]);
    }
}
