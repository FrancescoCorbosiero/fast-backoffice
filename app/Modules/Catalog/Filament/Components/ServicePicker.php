<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Filament\Components;

use App\Modules\Catalog\Filament\Resources\Services\ServiceResource;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Queries\Services;
use Filament\Forms\Components\Select;
use Illuminate\Support\HtmlString;

/**
 * The «Dal listino» field on a document line, for every other module's
 * forms: the active services to pick from, a «new service» form for the
 * one being typed right now, and a pointer to the listino when it is
 * still empty. Picking fills the line; the caller decides how.
 *
 *     ServicePicker::make('service_id')->afterStateUpdated(…)
 */
final class ServicePicker
{
    public static function make(string $name): Select
    {
        return Select::make($name)
            ->label('Dal listino')
            ->options(fn (): array => app(Services::class)->options())
            ->searchable()
            ->helperText(fn (): ?HtmlString => app(Services::class)->options() === []
                ? new HtmlString('Il listino è vuoto: <a href="'.e(ServiceResource::getUrl('index')).'" class="fi-link">aggiungi un servizio</a> o creane uno da qui.')
                : null)
            ->createOptionModalHeading('Nuovo servizio nel listino')
            ->createOptionForm(ServiceResource::fields())
            ->createOptionUsing(fn (array $data): int => Service::query()->create([...ServiceResource::toAttributes($data), 'sort' => ServiceResource::nextSort()])->id);
    }
}
