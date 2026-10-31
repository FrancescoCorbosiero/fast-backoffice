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

it('ships a production environment template with debug off and no secret filled in', function (): void {
    $template = (string) file_get_contents(base_path('.env.production.example'));

    expect($template)->toContain("APP_ENV=production\n")->toContain("APP_DEBUG=false\n")->toContain("SESSION_SECURE_COOKIE=true\n")
        ->and(preg_match('/^(APP_KEY|DB_PASSWORD|PEC_PASSWORD|AWS_SECRET_ACCESS_KEY|FILES_S3_SECRET|CALCOM_WEBHOOK_SECRET)=\S*[A-Za-z0-9+\/]{16,}/m', $template))->toBe(0)
        ->and((string) file_get_contents(base_path('.gitignore')))->toContain(".env.production\n");
});

it('keeps development tooling out of the runtime dependencies', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $runtime = array_keys(is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : []);

    foreach (['pestphp/pest', 'larastan/larastan', 'laravel/pint', 'fakerphp/faker', 'mockery/mockery', 'laravel/pail', 'barryvdh/laravel-debugbar', 'laravel/telescope'] as $package) {
        expect($runtime)->not->toContain($package);
    }

    $dockerfile = (string) file_get_contents(base_path('docker/prod/Dockerfile'));
    expect($dockerfile)->toContain('composer install --no-dev')->toContain('rm -rf tests');
    expect((string) file_get_contents(base_path('docker/prod/entrypoint.sh')))->toContain('APP_KEY is not set');
});

it('debug is off unless the environment says otherwise', function (): void {
    expect((string) file_get_contents(base_path('config/app.php')))->toContain("'debug' => (bool) env('APP_DEBUG', false)")
        ->and(config('app.env'))->toBe('testing');
});

it('keeps the panel behind the login and the public endpoints public', function (): void {
    $this->get('/admin')->assertRedirect();
    $this->get('/admin/invoices')->assertRedirect();
    $this->get('/up')->assertOk();
    $this->post('/webhooks/calcom', [], ['CONTENT_TYPE' => 'application/json'])->assertStatus(401);
    $this->get('/unsubscribe/nope')->assertNotFound();
});

it('never leaks exceptions on the public endpoints', function (): void {
    $this->call('POST', '/webhooks/ses', [], [], [], [], 'not json')->assertStatus(400)->assertJson(['outcome' => 'rejected']);
});
