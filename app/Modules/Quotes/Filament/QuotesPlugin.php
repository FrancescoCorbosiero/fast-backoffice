<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament;

use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class QuotesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'quotes';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([QuoteResource::class]);
    }

    public function boot(Panel $panel): void {}
}
