<?php

declare(strict_types=1);

use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Filament\Resources\Repositories\Pages\ManageRepositories;
use App\Modules\Repositories\Models\Repository;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
});

/*
 * The pipeline worker clones with a deploy key (docs/pipeline.md), so the
 * address of a private repository is the SSH one: git@github.com:studio/sito.git
 * or ssh://git@github.com/studio/sito.git. Both are repositories.
 */
it('accepts the SSH forms of a repository address and reads the provider from them', function (): void {
    expect(RepositoryProvider::fromUrl('git@github.com:studio/sito.git'))->toBe(RepositoryProvider::GitHub)
        ->and(RepositoryProvider::fromUrl('ssh://git@gitlab.com/studio/sito.git'))->toBe(RepositoryProvider::GitLab)
        ->and(RepositoryProvider::fromUrl('git@bitbucket.org:studio/sito.git'))->toBe(RepositoryProvider::Bitbucket)
        ->and(RepositoryProvider::fromUrl('deploy@git.studio.example:sito.git'))->toBe(RepositoryProvider::Other)
        ->and(RepositoryProvider::fromUrl('https://github.com/studio/sito'))->toBe(RepositoryProvider::GitHub);

    $repository = app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'sito', 'url' => 'git@github.com:studio/sito.git']));

    expect($repository->url)->toBe('git@github.com:studio/sito.git')
        ->and($repository->provider)->toBe(RepositoryProvider::GitHub);

    $viaScheme = app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'sito-ssh', 'url' => 'ssh://git@gitlab.com/studio/sito.git']));
    expect($viaScheme->provider)->toBe(RepositoryProvider::GitLab);
});

it('still refuses an address that is not a repository', function (string $url): void {
    expect(fn () => app(SaveRepository::class)->create(RepositoryInput::fromArray(['name' => 'x', 'url' => $url])))->toThrow(ValidationException::class);
})->with(['not a url', 'github.com/studio/sito', 'git@github.com', 'mailto:git@github.com', '']);

it('creates a repository with an SSH address from the panel', function (): void {
    signInAsOwner();

    Livewire::test(ManageRepositories::class)
        ->callAction('create', ['name' => 'sito', 'url' => 'git@github.com:studio/sito.git', 'default_branch' => 'main'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Repository::query()->sole()->provider)->toBe(RepositoryProvider::GitHub);
});

/*
 * What the owner saw: a repository whose address the use case refused left
 * the modal open with no error anywhere, because the use case's error keys
 * ("url") did not match the modal form's field paths. The error must land
 * on the field.
 */
it('shows the use case\'s validation error on the modal field instead of leaving the modal stuck', function (): void {
    signInAsOwner();
    Repository::factory()->create(['url' => 'https://github.com/studio-esempio/doppio']);

    Livewire::test(ManageRepositories::class)
        ->callAction('create', ['name' => 'sito', 'url' => 'not a url', 'default_branch' => 'main'])
        ->assertHasActionErrors(['url']);

    Livewire::test(ManageRepositories::class)
        ->callAction('create', ['name' => 'doppio', 'url' => 'https://github.com/studio-esempio/doppio', 'default_branch' => 'main'])
        ->assertHasActionErrors(['url']);

    expect(Repository::query()->count())->toBe(1);

    $repository = Repository::query()->sole();

    Livewire::test(ManageRepositories::class)
        ->callTableAction('edit', $repository, ['name' => '', 'url' => 'https://github.com/studio-esempio/doppio', 'default_branch' => 'main'])
        ->assertHasTableActionErrors(['name']);
});
