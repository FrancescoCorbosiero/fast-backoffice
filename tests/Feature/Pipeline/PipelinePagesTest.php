<?php

declare(strict_types=1);

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Pipeline\Contributions\ChangeRequestsContactPanel;
use App\Modules\Pipeline\Contributions\PipelineWebsitePanel;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ViewChangeRequest;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages\ManageWebsitePipelines;
use App\Modules\Pipeline\Filament\Widgets\ContactChangeRequestsTable;
use App\Modules\Pipeline\Filament\Widgets\PipelineOverviewWidget;
use App\Modules\Pipeline\Filament\Widgets\WebsitePipelineTable;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\OnboardRepository;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Pipeline\Site;

beforeEach(function (): void {
    signInAsOwner();
    Queue::fake();
    $this->travelTo('2026-03-15 10:00:00');
});

it('renders the pipeline screens', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/change-requests', '/admin/change-requests/create', '/admin/website-pipelines']);

it('submits a request for a client from the panel and shows it', function (): void {
    $site = Site::make();

    Livewire::test(CreateChangeRequest::class)
        ->fillForm(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'request_text' => 'Aggiungi il nuovo numero di telefono in fondo alla pagina.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = ChangeRequest::query()->sole();
    expect($request->status)->toBe(ChangeRequestStatus::Queued)->and($request->client_name)->toBe('Pasticceria Bellavista S.r.l.');
    Queue::assertPushed(RunStaticSiteRequest::class);

    $this->get("/admin/change-requests/{$request->id}")->assertOk()->assertSee('Aggiungi il nuovo numero')->assertSee('In coda')->assertSee('req/'.$request->id);
});

it('approves and rejects previews from the view page, owner as the decider', function (): void {
    $site = Site::make();
    $ready = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'log' => "Run completata.\nHo cambiato <b>gli orari</b>."]);
    $other = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    $this->get("/admin/change-requests/{$ready->id}")->assertOk()->assertSee('Anteprima pronta')->assertSee($ready->preview_url)->assertSee('content/orari.md')->assertSee('&lt;b&gt;gli orari&lt;/b&gt;', false);

    Livewire::test(ViewChangeRequest::class, ['record' => $ready->id])->callAction('approve')->assertNotified();
    expect($ready->refresh()->status)->toBe(ChangeRequestStatus::Approved)->and($ready->decided_by)->toBe(DecidedBy::Owner);
    Queue::assertPushed(DeployStaticSiteRequest::class);

    Livewire::test(ViewChangeRequest::class, ['record' => $other->id])->callAction('reject', ['reason' => 'Non è quello che voleva il cliente'])->assertNotified();
    expect($other->refresh()->status)->toBe(ChangeRequestStatus::Rejected)->and($other->decision_reason)->toBe('Non è quello che voleva il cliente');
    Queue::assertPushed(CleanupRequest::class);

    Livewire::test(ViewChangeRequest::class, ['record' => $ready->id])->assertActionHidden('approve')->assertActionHidden('reject');
    $this->get("/admin/change-requests/{$other->id}")->assertOk()->assertSee('Rifiutata da Studio')->assertSee('Non è quello che voleva il cliente');
});

it('shows the generated files of an onboarding and lets the owner approve them', function (): void {
    $site = Site::make(PipelineStatus::Onboarding);
    $onboarding = ChangeRequest::factory()->onboarding()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'preview_url' => null, 'generated_files' => ['pipeline.yml' => "build: hugo\noutput_dir: public\n", 'CLAUDE.md' => '# Sito Hugo']]);

    $this->get("/admin/change-requests/{$onboarding->id}")->assertOk()->assertSee('pipeline.yml')->assertSee('output_dir: public')->assertSee('# Sito Hugo')->assertSee('Onboarding #'.$onboarding->id);
    Livewire::test(ViewChangeRequest::class, ['record' => $onboarding->id])->callAction('approve')->assertNotified();
    expect($onboarding->refresh()->status)->toBe(ChangeRequestStatus::Approved);
});

it('links a repository from the pipelines screen, repeats and unlinks', function (): void {
    $site = Site::make(PipelineStatus::None);
    $site['pipeline']->delete();

    Livewire::test(ManageWebsitePipelines::class)
        ->callAction('create', ['website_id' => $site['website']->id, 'repository_id' => $site['repository']->id, 'kind' => 'static_site'])
        ->assertHasNoActionErrors();

    $pipeline = WebsitePipeline::query()->sole();
    expect($pipeline->status)->toBe(PipelineStatus::Onboarding);
    Queue::assertPushed(OnboardRepository::class, 1);

    ChangeRequest::query()->update(['status' => ChangeRequestStatus::Failed->value]);
    Livewire::test(ManageWebsitePipelines::class)->callTableAction('onboard', $pipeline)->assertNotified();
    Queue::assertPushed(OnboardRepository::class, 2);

    Livewire::test(ManageWebsitePipelines::class)->callTableAction('unlink', $pipeline)->assertNotified('Operazione non riuscita');
    ChangeRequest::query()->update(['status' => ChangeRequestStatus::Failed->value]);
    Livewire::test(ManageWebsitePipelines::class)->callTableAction('unlink', $pipeline)->assertNotified('Repository scollegato');
    expect(WebsitePipeline::query()->count())->toBe(0);

    $this->get('/admin/website-pipelines')->assertOk();
});

it('lists requests and pipelines in a constant number of queries, and counts them on the dashboard', function (): void {
    $site = Site::make();
    ChangeRequest::factory()->previewReady()->count(3)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    $count = function (callable $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    };

    $few = $count(fn () => Livewire::test(ListChangeRequests::class)->assertSee('Anteprima pronta'));
    for ($i = 0; $i < 20; $i++) {
        $website = Website::factory()->create(['contact_id' => $site['contact']->id, 'name' => "Sito {$i}", 'url' => "https://sito{$i}.example", 'host' => "sito{$i}.example"]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'contact_id' => $site['contact']->id]);
        WebsitePipeline::factory()->create(['website_id' => $website->id, 'repository_id' => $repository->id]);
        ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $website->id, 'website_name' => "Sito {$i}"]);
    }
    $many = $count(fn () => Livewire::test(ListChangeRequests::class)->assertSee('Sito 19'));
    expect($many)->toBe($few);

    $pipelinesFew = $count(fn () => Livewire::test(ManageWebsitePipelines::class)->assertSee('Sito 19')->assertSee($site['repository']->url.' (main)'));
    expect($pipelinesFew)->toBeLessThanOrEqual($few + 3);

    Livewire::test(PipelineOverviewWidget::class)->assertSee('Anteprime da approvare')->assertSee('23')->assertSee('Fallite negli ultimi 30 giorni')->assertSee('da controllare');
});

it('gives the website page a Pipeline section that leads to the next step: link, approve the onboarding, ask for a change', function (): void {
    $site = Site::make(PipelineStatus::None);
    $site['pipeline']->delete();
    $websiteId = $site['website']->id;

    expect(array_map(fn (WebsitePanel $panel): string => $panel->key(), iterator_to_array(app()->tagged(WebsitePanel::TAG), false)))->toContain('pipeline')
        ->and((new PipelineWebsitePanel)->createAction($websiteId)?->getLabel())->toBe('Collega repository');
    $this->get("/admin/websites/{$websiteId}")->assertOk()->assertSee('Pipeline')->assertSee('Non configurata')->assertSee('Nessun repository collegato')->assertSee('Collega repository');

    // The modal already knows the site and its only repository: the owner only confirms.
    Livewire::test(WebsitePipelineTable::class, ['websiteId' => $websiteId])
        ->mountTableAction('link-repository')->assertSchemaStateSet(['website_id' => $websiteId, 'repository_id' => $site['repository']->id, 'kind' => 'static_site'])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Onboarding avviato');
    expect(WebsitePipeline::query()->sole()->status)->toBe(PipelineStatus::Onboarding);
    Queue::assertPushed(OnboardRepository::class, 1);

    $onboarding = ChangeRequest::query()->sole();
    $onboarding->update(['status' => ChangeRequestStatus::PreviewReady]);
    Livewire::test(WebsitePipelineTable::class, ['websiteId' => $websiteId])->assertSee('In onboarding')->assertSee('Onboarding da approvare')->assertSee("/admin/change-requests/{$onboarding->id}", escape: false);
    expect((new PipelineWebsitePanel)->createAction($websiteId))->toBeNull();

    WebsitePipeline::query()->update(['status' => PipelineStatus::Ready->value]);
    Livewire::test(WebsitePipelineTable::class, ['websiteId' => $websiteId])->assertSee('Pronta')->assertSee($site['repository']->url.' (main)')->assertSee('Nuova richiesta')
        ->assertSee("contact_id={$site['contact']->id}&amp;website_id={$websiteId}", escape: false);
    expect((new PipelineWebsitePanel)->createAction($websiteId)?->getLabel())->toBe('Nuova richiesta');
});

it('links rows of Pipeline siti to the site, the onboarding to approve and the site\'s requests, and explains an empty list', function (): void {
    $this->get('/admin/website-pipelines')->assertOk()->assertSee('Nessun sito collegato')->assertSee('Collega repository');

    $site = Site::make(PipelineStatus::Onboarding);
    $onboarding = ChangeRequest::factory()->onboarding()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'preview_url' => null]);

    Livewire::test(ManageWebsitePipelines::class)
        ->assertSee("/admin/websites/{$site['website']->id}", escape: false)
        ->assertSee("/admin/change-requests/{$onboarding->id}", escape: false)
        ->assertTableActionVisible('onboarding', $site['pipeline'])
        ->assertSee('Onboarding da approvare')
        ->assertSee('website_id%5D%5Bvalue%5D='.$site['website']->id, escape: false);
});

it('lets the owner repeat a failed request or onboarding and nudge a deploy the worker never claimed', function (): void {
    $site = Site::make();
    $failed = ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'error' => 'Nessun commit.']);

    Livewire::test(ViewChangeRequest::class, ['record' => $failed->id])
        ->assertActionVisible('retry')->assertActionHidden('reonboard')->assertActionHidden('redeploy')
        ->mountAction('retry')->assertSchemaStateSet(['request_text' => $failed->request_text])
        ->callMountedAction()->assertHasNoActionErrors()->assertRedirect();
    $again = ChangeRequest::query()->orderByDesc('id')->first();
    expect($again?->id)->not->toBe($failed->id)->and($again?->request_text)->toBe($failed->request_text)->and($again?->website_id)->toBe($site['website']->id);
    Queue::assertPushed(RunStaticSiteRequest::class, 1);

    ChangeRequest::query()->update(['status' => ChangeRequestStatus::Failed->value]);
    $onboarding = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    Livewire::test(ViewChangeRequest::class, ['record' => $onboarding->id])->assertActionHidden('retry')->callAction('reonboard')->assertRedirect();
    Queue::assertPushed(OnboardRepository::class, 1);

    ChangeRequest::query()->update(['status' => ChangeRequestStatus::Failed->value]);
    $approved = ChangeRequest::factory()->previewReady()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    $this->get("/admin/change-requests/{$approved->id}")->assertOk()->assertSee('in attesa del worker')->assertSee('viene rimossa alla pubblicazione')->assertSee($approved->preview_url);
    Livewire::test(ViewChangeRequest::class, ['record' => $approved->id])->callAction('redeploy')->assertNotified('Pubblicazione rimessa in coda');
    Queue::assertPushed(DeployStaticSiteRequest::class, 1);

    $approved->update(['deploy_started_at' => now()->subMinute()]);
    Livewire::test(ViewChangeRequest::class, ['record' => $approved->id])->assertSee('avviata il')->assertActionHidden('redeploy');
});

it('filters the requests by site and client, links each card of the dashboard to its filter, and prefills the create form', function (): void {
    $site = Site::make();
    $other = Site::make(contact: ['display_name' => 'Altro Cliente', 'business_name' => 'Altro Cliente', 'vat_number' => '12345678903']);
    $mine = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    $theirs = ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $other['contact']->id, 'website_id' => $other['website']->id, 'client_name' => 'Altro Cliente']);

    Livewire::test(ListChangeRequests::class)
        ->assertSee("/admin/contacts/{$site['contact']->id}", escape: false)->assertSee("/admin/websites/{$site['website']->id}", escape: false)
        ->filterTable('website_id', $site['website']->id)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
        ->resetTableFilters()->filterTable('contact_id', $other['contact']->id)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);

    Livewire::test(PipelineOverviewWidget::class)
        ->assertSee('status%5D%5Bvalue%5D=preview_ready', escape: false)
        ->assertSee('status%5D%5Bvalue%5D=approved', escape: false)
        ->assertSee('status%5D%5Bvalue%5D=failed', escape: false);

    Livewire::withQueryParams(['contact_id' => (string) $site['contact']->id, 'website_id' => (string) $site['website']->id])
        ->test(CreateChangeRequest::class)
        ->assertSchemaStateSet(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    // The site select lists only the chosen client\'s ready sites.
    Livewire::test(CreateChangeRequest::class)
        ->fillForm(['contact_id' => $site['contact']->id, 'website_id' => $other['website']->id, 'request_text' => 'Prova'])
        ->call('create')->assertHasFormErrors(['website_id']);
});

it('explains an empty request list by the state of the pipelines, and gives the client\'s page a requests section', function (): void {
    $this->get('/admin/change-requests')->assertOk()->assertSee('Nessuna richiesta')->assertSee('Prima collega un repository')->assertSee('Pipeline siti');

    $site = Site::make(PipelineStatus::Onboarding);
    expect(array_map(fn (ContactPanel $panel): string => $panel->key(), iterator_to_array(app()->tagged(ContactPanel::TAG), false)))->toContain('change-requests')
        ->and((new ChangeRequestsContactPanel)->createAction($site['contact']->id)->getUrl())->toEndWith("/admin/change-requests/create?contact_id={$site['contact']->id}");
    $this->get("/admin/contacts/{$site['contact']->id}")->assertOk()->assertSee('Richieste di modifica');
    Livewire::test(ContactChangeRequestsTable::class, ['contactId' => $site['contact']->id])->assertSee('Nessun sito con la pipeline pronta')->assertSee('Pipeline siti');

    $site['pipeline']->update(['status' => PipelineStatus::Ready]);
    $request = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    Livewire::test(ContactChangeRequestsTable::class, ['contactId' => $site['contact']->id])
        ->assertSee('Anteprima pronta')->assertSee("/admin/change-requests/{$request->id}", escape: false)->assertSee('Nuova richiesta')->assertSee("contact_id={$site['contact']->id}", escape: false);
    $this->get('/admin/change-requests')->assertOk()->assertDontSee('Prima collega un repository');
});
