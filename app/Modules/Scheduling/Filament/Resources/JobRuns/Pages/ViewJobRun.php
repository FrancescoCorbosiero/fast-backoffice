<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns\Pages;

use App\Modules\Scheduling\Actions\RunJob;
use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Filament\Pages\JobsPage;
use App\Modules\Scheduling\Filament\Resources\JobRuns\JobRunResource;
use App\Modules\Scheduling\Models\JobRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewJobRun extends ViewRecord
{
    protected static string $resource = JobRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rerun')
                ->label('Esegui di nuovo')
                ->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->modalDescription('L\'operazione viene eseguita subito, in questa richiesta.')
                ->action(function (JobRun $record): void {
                    $run = app(RunJob::class)->run($record->job_key, RunTrigger::Manual);
                    $notification = Notification::make()
                        ->title($run->succeeded() ? 'Eseguita' : 'Terminata con errore')
                        ->body($run->output);
                    $run->succeeded() ? $notification->success()->send() : $notification->danger()->send();

                    $this->redirect(JobRunResource::getUrl('view', ['record' => $run->id]));
                }),
            Action::make('history')
                ->label('Altre esecuzioni')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn (JobRun $record): string => JobRunResource::getUrl('index', ['filters' => ['job_key' => ['value' => $record->job_key]]])),
            Action::make('jobs')
                ->label('Operazioni pianificate')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(JobsPage::getUrl()),
        ];
    }
}
