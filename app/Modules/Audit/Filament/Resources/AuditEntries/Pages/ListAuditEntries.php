<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditEntries\Pages;

use App\Modules\Audit\Filament\Resources\AuditEntries\AuditEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditEntryResource::class;
}
