<?php

declare(strict_types=1);

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Contributions\RepositoriesContactPanel;
use App\Modules\Repositories\Contributions\RepositoriesWebsitePanel;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Filament\Resources\Repositories\Pages\ManageRepositories;
use App\Modules\Repositories\Filament\Widgets\ContactRepositoriesTable;
use App\Modules\Repositories\Filament\Widgets\WebsiteRepositoriesTable;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Models\Website;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
});

it('offers to add the first repository from the contact page, already knowing the client', function (): void {
    $client = Studio::client();

    Livewire::test(ContactRepositoriesTable::class, ['contactId' => $client->id])
        ->assertSee('Repository')
        ->assertSee('Nessun repository')
        ->assertSee('La pipeline lavora su un repository')
        ->assertSee('Nuovo repository')
        ->mountAction(TestAction::make('new-repository')->table())
        ->assertSchemaStateSet(['contact_id' => $client->id])
        ->setActionData(['name' => 'bellavista-site', 'url' => 'https://github.com/studio/bellavista', 'default_branch' => 'main'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Repository creato')
        ->assertSee('github.com/studio/bellavista');

    $repository = Repository::query()->sole();
    expect($repository->contact_id)->toBe($client->id)
        ->and($repository->website_id)->toBeNull()
        ->and($repository->provider)->toBe(RepositoryProvider::GitHub);

    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Repository')->assertSee('Nuovo repository');
});

it('shows what the save action refuses on the modal form instead of failing silently', function (): void {
    $client = Studio::client();
    Repository::factory()->create(['url' => 'https://github.com/studio/bellavista']);

    Livewire::test(ContactRepositoriesTable::class, ['contactId' => $client->id])
        ->callAction(TestAction::make('new-repository')->table(), ['name' => 'doppione', 'url' => 'https://github.com/studio/bellavista', 'contact_id' => $client->id])
        ->assertHasActionErrors(['url'])
        ->assertNotNotified('Repository creato');

    expect(Repository::query()->count())->toBe(1);
});

it('offers to add a repository from the website page, already knowing the site and its client', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $this->get("/admin/websites/{$website->id}")->assertOk()->assertSee('Repository')->assertSee('Nuovo repository')->assertSee('Nessun repository');

    Livewire::test(WebsiteRepositoriesTable::class, ['websiteId' => $website->id])
        ->assertSee('Nessun repository')
        ->assertSee('Nuovo repository')
        ->mountAction(TestAction::make('new-repository')->table())
        ->assertSchemaStateSet(['contact_id' => $client->id, 'website_id' => $website->id])
        ->setActionData(['name' => 'bellavista-site', 'url' => 'https://gitlab.com/studio/bellavista'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Repository creato')
        ->assertSee('gitlab.com/studio/bellavista');

    $repository = Repository::query()->sole();
    expect($repository->contact_id)->toBe($client->id)->and($repository->website_id)->toBe($website->id);

    // Only this site's repositories show here.
    Repository::factory()->create(['contact_id' => $client->id, 'website_id' => null, 'url' => 'https://github.com/studio/other']);
    Livewire::test(WebsiteRepositoriesTable::class, ['websiteId' => $website->id])->assertSee('gitlab.com/studio/bellavista')->assertDontSee('github.com/studio/other');
});

it('feeds the "Nuovo" menus of both pages', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $contactPanel = collect(app()->tagged(ContactPanel::TAG))->first(fn (ContactPanel $panel): bool => $panel->key() === 'repositories');
    $websitePanel = collect(app()->tagged(WebsitePanel::TAG))->first(fn (WebsitePanel $panel): bool => $panel->key() === 'repositories');

    expect($contactPanel)->toBeInstanceOf(RepositoriesContactPanel::class)
        ->and($contactPanel?->createAction($client->id)?->getLabel())->toBe('Nuovo repository')
        ->and($contactPanel?->createAction($client->id)?->getUrl())->toBeNull()
        ->and($websitePanel)->toBeInstanceOf(RepositoriesWebsitePanel::class)
        ->and($websitePanel?->sort())->toBe(20)
        ->and($websitePanel?->label())->toBe('Repository')
        ->and($websitePanel?->createAction($website->id)?->getLabel())->toBe('Nuovo repository');
});

it('lists each repository with its site and client, opens the create modal prefilled from the query string, and couples site and client on the form', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id, 'name' => 'Sito Bellavista']);
    $repository = Repository::factory()->create(['contact_id' => $client->id, 'website_id' => $website->id, 'url' => 'https://github.com/studio/bellavista']);
    $other = Repository::factory()->create(['url' => 'https://github.com/studio/other']);

    Livewire::test(ManageRepositories::class)
        ->assertSee('Pasticceria Bellavista')->assertSee("/admin/contacts/{$client->id}", escape: false)
        ->assertSee('Sito Bellavista')->assertSee("/admin/websites/{$website->id}", escape: false)
        ->filterTable('website_id', $website->id)->assertCanSeeTableRecords([$repository])->assertCanNotSeeTableRecords([$other]);

    Livewire::withQueryParams(['contact_id' => (string) $client->id, 'website_id' => (string) $website->id])
        ->test(ManageRepositories::class)
        ->mountAction('create')
        ->assertSchemaStateSet(['contact_id' => $client->id, 'website_id' => $website->id]);

    Livewire::test(ManageRepositories::class)
        ->mountAction('create')
        ->setActionData(['website_id' => $website->id])
        ->assertSchemaStateSet(['contact_id' => $client->id]);
});

it('refuses to delete a repository a pipeline is built from, saying what to do first', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);
    $repository = Repository::factory()->create(['contact_id' => $client->id, 'website_id' => $website->id, 'url' => 'https://github.com/studio/bellavista']);
    WebsitePipeline::factory()->create(['website_id' => $website->id, 'repository_id' => $repository->id]);

    Livewire::test(ManageRepositories::class)
        ->callTableAction('delete', $repository)
        ->assertNotified('Repository non eliminato')
        ->assertNotNotified('Eliminato');

    expect(Repository::query()->count())->toBe(1);
});
