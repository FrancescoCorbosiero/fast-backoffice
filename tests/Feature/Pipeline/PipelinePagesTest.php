<?php

declare(strict_types=1);

use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ViewChangeRequest;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages\ManageWebsitePipelines;
use App\Modules\Pipeline\Filament\Widgets\PipelineOverviewWidget;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\OnboardRepository;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Models\Repository;
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
