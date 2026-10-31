<?php

declare(strict_types=1);

use App\Modules\Catalog\Filament\Resources\Services\Pages\ManageServices;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Queries\Services;
use Livewire\Livewire;

it('offers services as line templates that documents copy', function (): void {
    $service = Service::factory()->flatRate()->create(['name' => 'Sito vetrina', 'description' => 'Cinque pagine', 'unit_price_cents' => 150_000]);
    Service::factory()->create(['name' => 'Spento', 'is_active' => false]);

    $data = app(Services::class)->find($service->id);

    expect($data?->lineTemplate())->toBe(['description' => "Sito vetrina\nCinque pagine", 'quantity' => '1', 'unit_price' => '1500.00', 'vat_basis_points' => 0, 'natura' => 'N2.2'])
        ->and(app(Services::class)->active())->toHaveCount(1)
        ->and(app(Services::class)->options())->toHaveKey($service->id);
});

it('manages the catalog from the panel with prices and VAT as people write them', function (): void {
    signInAsOwner();

    Livewire::test(ManageServices::class)
        ->callAction('create', ['name' => 'Consulenza', 'unit_price' => '80,00', 'vat_percent' => '22', 'is_active' => true])
        ->assertHasNoActionErrors();

    $service = Service::query()->sole();
    expect($service->unit_price_cents)->toBe(8_000)->and($service->vat_basis_points)->toBe(2200)->and($service->natura)->toBeNull();

    Livewire::test(ManageServices::class)
        ->callTableAction('edit', $service, ['name' => 'Consulenza', 'unit_price' => '90', 'vat_percent' => '0', 'natura' => 'N2.2', 'is_active' => true])
        ->assertHasNoTableActionErrors();

    expect($service->refresh()->unit_price_cents)->toBe(9_000)->and($service->natura?->value)->toBe('N2.2');
});
