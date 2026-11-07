<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns\Tables;

use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Models\JobRun;
use App\Modules\Scheduling\Queries\ListJobs;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JobRunsTable
{
    public static function configure(Table $table): Table
    {
        $labels = app(ListJobs::class)->labels();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Inizio')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('job_key')->label('Operazione')->searchable()
                    ->formatStateUsing(fn (string $state): string => $labels[$state] ?? $state)
                    ->description(fn (JobRun $record): string => $record->job_key),
                TextColumn::make('trigger')->label('Avvio')->formatStateUsing(fn (RunTrigger $state): string => $state->label()),
                TextColumn::make('duration')->label('Durata')
                    ->state(fn (JobRun $record): string => $record->finished_at === null ? 'in corso' : $record->finished_at->diffForHumans($record->started_at, CarbonInterface::DIFF_ABSOLUTE)),
                TextColumn::make('exit_code')->label('Esito')->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'ok' : "errore ({$state})")
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'danger')
                    ->placeholder('in corso'),
            ])
            ->filters([
                SelectFilter::make('job_key')->label('Operazione')->options($labels),
                SelectFilter::make('trigger')->label('Avvio')->options([
                    RunTrigger::Scheduler->value => RunTrigger::Scheduler->label(),
                    RunTrigger::Manual->value => RunTrigger::Manual->label(),
                ]),
                TernaryFilter::make('failed')->label('Esito')
                    ->trueLabel('Solo errori')
                    ->falseLabel('Solo ok')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('exit_code', '!=', 0),
                        false: fn (Builder $query): Builder => $query->where('exit_code', 0),
                    ),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50, 100]);
    }
}
