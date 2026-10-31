<?php

declare(strict_types=1);

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\CashFlowProjection;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Websites\Actions\CreateWebsite;
use App\Modules\Websites\Actions\LogHostingRenewal;
use App\Modules\Websites\Actions\RecordUptimeResults;
use App\Modules\Websites\Actions\SetWebsiteStatus;
use App\Modules\Websites\Actions\StartSubscription;
use App\Modules\Websites\Actions\StopSubscription;
use App\Modules\Websites\Actions\UpdateWebsite;
use App\Modules\Websites\Data\UptimeResult;
use App\Modules\Websites\Data\WebsiteInput;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Events\HostingRenewed;
use App\Modules\Websites\Events\WebsiteDown;
use App\Modules\Websites\Events\WebsiteRecovered;
use App\Modules\Websites\Integrations\UptimeChecker;
use App\Modules\Websites\Jobs\CheckUptime;
use App\Modules\Websites\Models\Website;
use App\Modules\Websites\Queries\Websites;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15 10:00:00');
});

it('registers a site for a client, with the host it answers on', function (): void {
    $client = Studio::client();

    $website = app(CreateWebsite::class)->create(WebsiteInput::fromArray(['contact_id' => $client->id, 'name' => 'Bellavista', 'url' => 'https://WWW.Bellavista.example/it/']));

    expect($website->host)->toBe('www.bellavista.example')
        ->and($website->status)->toBe(WebsiteStatus::Active)
        ->and($website->isMonitored())->toBeTrue()
        ->and($website->hasHostingRenewal())->toBeFalse()
        ->and(app(Websites::class)->byHost('WWW.bellavista.example')?->id)->toBe($website->id)
        ->and(app(Websites::class)->forContact($client->id))->toHaveCount(1);

    expect(fn () => app(CreateWebsite::class)->create(WebsiteInput::fromArray(['contact_id' => $client->id, 'name' => 'x', 'url' => 'bellavista.example'])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateWebsite::class)->create(WebsiteInput::fromArray(['contact_id' => 999_999, 'name' => 'x', 'url' => 'https://a.example'])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateWebsite::class)->create(WebsiteInput::fromArray(['contact_id' => $client->id, 'name' => 'x', 'url' => 'https://a.example', 'hosting_cost' => '10'])))->toThrow(ValidationException::class);
});

it('logs a hosting renewal once per period and rolls the date by the step rule', function (): void {
    Event::fake([HostingRenewed::class]);
    $client = Studio::client();
    $account = Studio::account();
    $website = Website::factory()->hosted('2026-02-28', 12_000, 'monthly')->create(['contact_id' => $client->id, 'hosting_started_on' => '2026-01-31', 'hosting_account_id' => $account->id]);

    $first = app(LogHostingRenewal::class)->log($website->id);
    $renewsAfterFirst = $website->refresh()->hosting_renews_on->toDateString();
    $again = app(LogHostingRenewal::class)->log($website->id, CarbonImmutable::parse('2026-03-02'));

    expect($first->amount->cents)->toBe(12_000)
        ->and($first->direction->value)->toBe('out')
        ->and($first->occurredOn->toDateString())->toBe('2026-02-28')
        ->and($first->allocation?->toString())->toBe("hosting_renewal:{$website->id}:2026-02-28")
        ->and($first->description)->toContain('Hosting')
        ->and($renewsAfterFirst)->toBe('2026-03-31')
        ->and($again->allocation?->toString())->toBe("hosting_renewal:{$website->id}:2026-03-31")
        ->and($website->refresh()->hosting_renews_on->toDateString())->toBe('2026-04-30')
        ->and(Movement::query()->count())->toBe(2);
    Event::assertDispatchedTimes(HostingRenewed::class, 2);

    Movement::query()->where('allocation', "hosting_renewal:{$website->id}:2026-04-30")->delete();
    Movement::factory()->for($account)->out(12_000, '2026-04-30')->allocatedTo("hosting_renewal:{$website->id}:2026-04-30")->create();
    $logged = app(LogHostingRenewal::class)->log($website->id);
    expect($logged->occurredOn->toDateString())->toBe('2026-04-30')->and(Movement::query()->count())->toBe(3);

    $free = Website::factory()->create(['contact_id' => $client->id]);
    expect(fn () => app(LogHostingRenewal::class)->log($free->id))->toThrow(ValidationException::class);
    $noAccount = Website::factory()->hosted()->create(['contact_id' => $client->id, 'url' => 'https://two.example', 'host' => 'two.example']);
    expect(fn () => app(LogHostingRenewal::class)->log($noAccount->id))->toThrow(ValidationException::class);
});

it('contributes hosting renewals to the cash-flow projection, overdue ones included', function (): void {
    $client = Studio::client();
    Website::factory()->hosted('2026-02-15', 12_000, 'yearly')->create(['contact_id' => $client->id]);
    Website::factory()->hosted('2026-04-30', 3_000, 'monthly')->create(['contact_id' => $client->id, 'url' => 'https://two.example', 'host' => 'two.example']);
    Website::factory()->hosted('2026-04-01', 9_000)->create(['contact_id' => $client->id, 'url' => 'https://closed.example', 'host' => 'closed.example', 'status' => WebsiteStatus::Closed]);

    $projection = app(CashFlowProjection::class)->project(CarbonImmutable::parse('2026-03-15'), 3);
    $flows = array_map(fn ($f): array => [$f->date->toDateString(), $f->source, $f->amount->cents], $projection->flows);

    expect($flows)->toBe([
        ['2026-02-15', 'hosting_renewal', 12_000],
        ['2026-04-30', 'hosting_renewal', 3_000],
        ['2026-05-30', 'hosting_renewal', 3_000],
    ])->and($projection->isOverdue($projection->flows[0]))->toBeTrue();
});

it('starts a subscription through Invoicing and keeps the schedule; closing the site stops it', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $withSubscription = app(StartSubscription::class)->start($website->id, [App\Modules\Invoicing\Data\LineInput::fromArray(Studio::line('Canone sito', '50.00'))], Frequency::Monthly, CarbonImmutable::parse('2026-04-01'), autoIssue: true);

    $schedule = RecurringInvoice::query()->findOrFail($withSubscription->subscriptionId)->toData();
    expect($schedule->name)->toBe('Canone sito Pasticceria Bellavista')
        ->and($schedule->contactId)->toBe($client->id)
        ->and($schedule->frequency)->toBe(Frequency::Monthly)
        ->and($schedule->nextIssueOn->toDateString())->toBe('2026-04-01')
        ->and($schedule->autoIssue)->toBeTrue()
        ->and($schedule->isActive)->toBeTrue()
        ->and($schedule->lines[0]->description)->toBe('Canone sito');

    expect(fn () => app(StartSubscription::class)->start($website->id, [App\Modules\Invoicing\Data\LineInput::fromArray(Studio::line('x', '1.00'))], Frequency::Monthly, CarbonImmutable::today()))->toThrow(LogicException::class);

    app(SetWebsiteStatus::class)->set($website->id, WebsiteStatus::Closed);
    expect(RecurringInvoice::query()->findOrFail($schedule->id)->is_active)->toBeFalse()
        ->and($website->refresh()->status)->toBe(WebsiteStatus::Closed);

    $reopened = app(SetWebsiteStatus::class)->set($website->id, WebsiteStatus::Active);
    $stopped = app(StopSubscription::class)->stop($website->id);
    expect($reopened->subscriptionId)->toBe($schedule->id)->and($stopped->subscriptionId)->toBeNull();
});

it('checks every monitored site at once and declares one down after two failures', function (): void {
    Event::fake([WebsiteDown::class, WebsiteRecovered::class]);
    $client = Studio::client();
    $up = Website::factory()->create(['contact_id' => $client->id]);
    $down = Website::factory()->create(['contact_id' => $client->id, 'name' => 'Giù', 'url' => 'https://down.example', 'host' => 'down.example']);
    $gone = Website::factory()->create(['contact_id' => $client->id, 'name' => 'Sparito', 'url' => 'https://gone.example', 'host' => 'gone.example']);
    Website::factory()->create(['contact_id' => $client->id, 'name' => 'Ignorato', 'url' => 'https://off.example', 'host' => 'off.example', 'uptime_enabled' => false]);
    Website::factory()->create(['contact_id' => $client->id, 'name' => 'Chiuso', 'url' => 'https://closed.example', 'host' => 'closed.example', 'status' => WebsiteStatus::Closed]);

    Http::preventStrayRequests();
    Http::fake([
        'https://www.bellavista.example*' => Http::response('ok', 200),
        'https://down.example*' => Http::response('oops', 503),
        'https://gone.example*' => fn (Request $request) => throw new ConnectionException('Could not resolve host'),
    ]);

    $result = app(CheckUptime::class)->run();

    Http::assertSentCount(2); // the fake records the two that answered; the one that threw never got a response
    expect($result->succeeded())->toBeFalse()
        ->and($result->output)->toContain('3 siti controllati, 2 non rispondono')->toContain('Giù: HTTP 503')->toContain('Sparito: Could not resolve host')
        ->and($up->refresh()->is_up)->toBeTrue()->and($up->last_status_code)->toBe(200)->and($up->last_checked_at)->not->toBeNull()
        ->and($down->refresh()->is_up)->toBeFalse()->and($down->consecutive_failures)->toBe(1)->and($down->down_since)->toBeNull();
    Event::assertNotDispatched(WebsiteDown::class);

    app(CheckUptime::class)->run();
    expect($down->refresh()->consecutive_failures)->toBe(2)->and($down->down_since)->not->toBeNull()
        ->and($gone->refresh()->down_since)->not->toBeNull()
        ->and(app(Websites::class)->down())->toHaveCount(2);
    Event::assertDispatchedTimes(WebsiteDown::class, 2);

    app(RecordUptimeResults::class)->record([new UptimeResult($down->id, true, 200, 120)]);
    expect($down->refresh()->down_since)->toBeNull()->and($down->consecutive_failures)->toBe(0);
    Event::assertDispatchedTimes(WebsiteRecovered::class, 1);

    expect(app(UptimeChecker::class)->check([]))->toBe([]);
});

it('edits a site without losing the hosting anchor, and follows a merged contact', function (): void {
    $client = Studio::client();
    $website = Website::factory()->hosted('2026-03-31', 12_000, 'monthly')->create(['contact_id' => $client->id, 'hosting_started_on' => '2026-01-31']);

    $updated = app(UpdateWebsite::class)->update($website->id, WebsiteInput::fromArray(['contact_id' => $client->id, 'name' => 'Rinominato', 'url' => $website->url, 'hosting_renews_on' => '2026-03-31', 'hosting_frequency' => 'monthly', 'hosting_cost' => '120,00', 'hosting_provider' => 'Hetzner']));
    expect($updated->name)->toBe('Rinominato')->and($updated->hostingStartedOn?->toDateString())->toBe('2026-01-31')->and($updated->hostingCost?->cents)->toBe(12_000);

    $moved = app(UpdateWebsite::class)->update($website->id, WebsiteInput::fromArray(['contact_id' => $client->id, 'name' => 'Rinominato', 'url' => $website->url, 'hosting_renews_on' => '2026-04-15', 'hosting_frequency' => 'monthly', 'hosting_cost' => '120']));
    expect($moved->hostingStartedOn?->toDateString())->toBe('2026-04-15');

    $other = Studio::client(['vat_number' => '12345678903', 'display_name' => 'Doppione', 'business_name' => 'Doppione']);
    app(MergeContacts::class)->merge($other->id, $client->id);
    expect(Website::query()->findOrFail($website->id)->contact_id)->toBe($other->id);
});
