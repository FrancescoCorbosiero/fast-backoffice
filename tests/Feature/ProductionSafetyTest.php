<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

it('seeds no user and no guessable password', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->exists())->toBeFalse();
});

it('never creates a user with the password "password"', function (): void {
    $this->artisan('owner:create', ['email' => 'owner@studio.test', '--password' => 'password'])->assertFailed();

    expect(User::query()->exists())->toBeFalse();
});

it('opens Horizon to the signed-in owner, and only to them', function (): void {
    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();

    $this->get('/horizon')->assertForbidden();
});

it('reads settings from config, never env, so config:cache cannot reset them', function (): void {
    expect(config('backoffice.files_disk'))->toBe('files-local')
        ->and(config('backoffice.currency'))->toBe('EUR')
        ->and(config('app.timezone'))->toBe('Europe/Rome');
});

it('keeps the owner model free of mass-assignable secrets', function (): void {
    $user = new User;

    expect($user->isFillable('app_authentication_secret'))->toBeFalse()
        ->and($user->isFillable('app_authentication_recovery_codes'))->toBeFalse();
});

it('hashes passwords on the way in', function (): void {
    $owner = User::factory()->create(['password' => 'a long and private password']);

    expect($owner->password)->not->toBe('a long and private password')
        ->and(Hash::check('a long and private password', $owner->password))->toBeTrue();
});
