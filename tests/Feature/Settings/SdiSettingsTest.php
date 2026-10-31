<?php

declare(strict_types=1);

use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Data\SdiSettings;
use App\Modules\Settings\Filament\Pages\SdiSettingsPage;
use App\Modules\Settings\Queries\GetSdiSettings;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('starts pointed at SdI\'s first-contact address with both switches off', function (): void {
    $settings = app(GetSdiSettings::class)->get();

    expect($settings->pecAddress)->toBeNull()
        ->and($settings->sdiAddress)->toBe(SdiSettings::FIRST_SDI_ADDRESS)
        ->and($settings->autoSend)->toBeFalse()
        ->and($settings->inboxEnabled)->toBeFalse()
        ->and($settings->awaitsSdiReplyAddress())->toBeTrue();
});

it('saves the addresses and switches, and refuses switches without a mailbox address', function (): void {
    app(SaveSdiSettings::class)->save(new SdiSettings('Studio@PEC.test', 'sdi05@pec.fatturapa.it', true, true));

    $settings = app(GetSdiSettings::class)->get();
    expect($settings->pecAddress)->toBe('studio@pec.test')->and($settings->sdiAddress)->toBe('sdi05@pec.fatturapa.it')->and($settings->autoSend)->toBeTrue()->and($settings->awaitsSdiReplyAddress())->toBeFalse();

    expect(fn () => app(SaveSdiSettings::class)->save(new SdiSettings(null, SdiSettings::FIRST_SDI_ADDRESS, true, false)))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveSdiSettings::class)->save(new SdiSettings('not-an-address', SdiSettings::FIRST_SDI_ADDRESS)))->toThrow(ValidationException::class);

    expect($settings->withSdiAddress('SDI14@pec.fatturapa.it')->sdiAddress)->toBe('sdi14@pec.fatturapa.it');
});

it('is edited from its settings page', function (): void {
    signInAsOwner();

    $this->get('/admin/settings/sdi')->assertOk()->assertSee('non configurato');

    Livewire::test(SdiSettingsPage::class)
        ->fillForm(['pec_address' => 'studio@pec.test', 'sdi_address' => SdiSettings::FIRST_SDI_ADDRESS, 'auto_send' => false, 'inbox_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GetSdiSettings::class)->get()->inboxEnabled)->toBeTrue();
});
