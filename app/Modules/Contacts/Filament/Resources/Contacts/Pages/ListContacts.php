<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Pages;

use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
