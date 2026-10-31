<?php

declare(strict_types=1);

use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Filament\Resources\Repositories\Pages\ManageRepositories;
use App\Modules\Repositories\Filament\Widgets\ContactRepositoriesTable;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Models\Website;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
});

it('links a repository to a client and a site, reading the provider from the address', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $repository = app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'bellavista-site', 'url' => 'https://gitlab.com/studio/bellavista', 'contact_id' => $client->id, 'website_id' => $website->id]));

    expect($repository->provider)->toBe(RepositoryProvider::GitLab)
        ->and($repository->defaultBranch)->toBe('main')
        ->and(app(Repositories::class)->forWebsite($website->id))->toHaveCount(1)
        ->and(app(Repositories::class)->forContact($client->id)[0]->name)->toBe('bellavista-site')
        ->and(RepositoryProvider::fromUrl('git@github.com:studio/x.git'))->toBe(RepositoryProvider::Other)
        ->and(RepositoryProvider::fromUrl('https://bitbucket.org/studio/x'))->toBe(RepositoryProvider::Bitbucket);

    expect(fn () => app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'dup', 'url' => 'https://gitlab.com/studio/bellavista'])))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'x', 'url' => 'not a url'])))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'x', 'url' => 'https://github.com/a/b', 'website_id' => 999])))->toThrow(ValidationException::class);

    $updated = app(SaveRepository::class)->update($repository->id, RepositoryInput::fromArray(['name' => 'bellavista-site', 'url' => 'https://github.com/studio/bellavista', 'default_branch' => 'develop']));
    expect($updated->provider)->toBe(RepositoryProvider::GitHub)->and($updated->defaultBranch)->toBe('develop')->and($updated->contactId)->toBeNull();

    app(SaveRepository::class)->delete($repository->id);
    expect(Repository::query()->count())->toBe(0);
});

it('is managed from the panel and listed on the contact page', function (): void {
    signInAsOwner();
    $client = Studio::client();

    $this->get('/admin/repositories')->assertOk();

    Livewire::test(ManageRepositories::class)
        ->callAction('create', ['name' => 'sito', 'url' => 'https://github.com/studio/sito', 'contact_id' => $client->id, 'default_branch' => 'main'])
        ->assertHasNoActionErrors();
    $repository = Repository::query()->sole();
    expect($repository->provider)->toBe(RepositoryProvider::GitHub);

    Livewire::test(ManageRepositories::class)
        ->callTableAction('edit', $repository, ['name' => 'sito-v2', 'url' => 'https://github.com/studio/sito', 'default_branch' => 'main'])
        ->assertHasNoTableActionErrors();
    expect($repository->refresh()->name)->toBe('sito-v2');

    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Repository');
    Livewire::test(ContactRepositoriesTable::class, ['contactId' => $client->id])->assertSee('github.com/studio/sito');

    Livewire::test(ManageRepositories::class)->callTableAction('delete', $repository);
    expect(Repository::query()->count())->toBe(0);
});
