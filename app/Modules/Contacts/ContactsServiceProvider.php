<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Modules\Contacts\Filament\ContactsPlugin;
use App\Support\ModuleServiceProvider;

class ContactsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Contacts';
    }

    protected function plugin(): ?string
    {
        return ContactsPlugin::class;
    }
}
