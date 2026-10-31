<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament;

use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ContactsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'contacts';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ContactResource::class]);
    }

    public function boot(Panel $panel): void {}
}
