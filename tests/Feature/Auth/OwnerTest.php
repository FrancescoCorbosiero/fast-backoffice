<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the owner from the command', function (): void {
    $this->artisan('owner:create', ['email' => 'owner@studio.test', '--name' => 'Owner', '--password' => 'a long and private password'])
        ->assertSuccessful();

    $owner = User::query()->sole();

    expect($owner->email)->toBe('owner@studio.test')
        ->and($owner->name)->toBe('Owner')
        ->and(Hash::check('a long and private password', $owner->password))->toBeTrue();
});

it('refuses a second owner: one owner, one login', function (): void {
    User::factory()->create();

    $this->artisan('owner:create', ['email' => 'second@studio.test', '--password' => 'a long and private password'])
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('refuses a short password or a bad email', function (array $arguments): void {
    $this->artisan('owner:create', $arguments)->assertFailed();

    expect(User::query()->exists())->toBeFalse();
})->with([
    'short password' => [['email' => 'owner@studio.test', '--password' => 'short']],
    'bad email' => [['email' => 'not-an-email', '--password' => 'a long and private password']],
]);

it('redirects guests to the login page', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/')->assertRedirect('/admin');
});

it('shows the login page', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('opens the panel without two-factor authentication, which the owner can turn on from the profile', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/admin')->assertOk();
    $this->actingAs($owner)->get('/admin/profile')->assertOk()->assertSee('Autenticazione a due fattori');

    $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $this->actingAs($owner->fresh())->get('/admin')->assertOk();
});

it('stores the two-factor secret and recovery codes encrypted', function (): void {
    $owner = User::factory()->create();
    $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $owner->saveAppAuthenticationRecoveryCodes(['one', 'two']);

    $raw = (array) Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->first();

    expect($raw['app_authentication_secret'])->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($raw['app_authentication_recovery_codes'])->not->toContain('one')
        ->and($owner->fresh()?->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and($owner->fresh()?->getAppAuthenticationRecoveryCodes())->toBe(['one', 'two'])
        ->and($owner->toArray())->not->toHaveKeys(['password', 'app_authentication_secret', 'app_authentication_recovery_codes']);
});
