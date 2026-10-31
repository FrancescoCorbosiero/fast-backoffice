<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
