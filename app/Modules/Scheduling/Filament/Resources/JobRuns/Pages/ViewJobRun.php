<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Resources\JobRuns\Pages;

use App\Modules\Scheduling\Filament\Resources\JobRuns\JobRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJobRun extends ViewRecord
{
    protected static string $resource = JobRunResource::class;
}
