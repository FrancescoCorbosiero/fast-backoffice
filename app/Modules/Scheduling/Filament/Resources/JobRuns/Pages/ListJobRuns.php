<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns\Pages;

use App\Modules\Scheduling\Filament\Pages\JobsPage;
use App\Modules\Scheduling\Filament\Resources\JobRuns\JobRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListJobRuns extends ListRecords
{
    protected static string $resource = JobRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('jobs')
                ->label('Operazioni pianificate')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(JobsPage::getUrl()),
        ];
    }
}
