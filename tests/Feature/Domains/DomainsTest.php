<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\CashFlowProjection;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Actions\LogDomainRenewal;
use App\Modules\Domains\Actions\SetDomainStatus;
use App\Modules\Domains\Actions\UpdateDomain;
use App\Modules\Domains\Data\DomainInput;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Events\DomainRenewed;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain as CreateDomainPage;
use App\Modules\Domains\Filament\Resources\Domains\Pages\EditDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ListDomains;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ViewDomain;
use App\Modules\Domains\Filament\Widgets\ContactDomainsTable;
use App\Modules\Domains\Filament\Widgets\DomainsExpiringWidget;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Queries\Domains;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15 10:00:00');
});

it('registers a domain, lower-case and unique, linked to a client and a site', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $domain = app(CreateDomain::class)->create(DomainInput::fromArray(['name' => 'Bellavista.Example', 'contact_id' => $client->id, 'website_id' => $website->id, 'expires_on' => '2026-06-30', 'renewal_cost' => '15,00', 'registrar' => 'OVH']));

    expect($domain->name)->toBe('bellavista.example')
        ->and($domain->status)->toBe(DomainStatus::Active)
        ->and($domain->renewalCost?->cents)->toBe(1_500)
        ->and($domain->renewalMonths)->toBe(12)
        ->and($domain->websiteId)->toBe($website->id)
        ->and(app(Domains::class)->forWebsite($website->id))->toHaveCount(1)
        ->and(app(Domains::class)->forContact($client->id))->toHaveCount(1);

    expect(fn () => app(CreateDomain::class)->create(DomainInput::fromArray(['name' => 'bellavista.example', 'contact_id' => $client->id, 'expires_on' => '2027-01-01'])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateDomain::class)->create(DomainInput::fromArray(['name' => 'https://x.example', 'contact_id' => $client->id, 'expires_on' => '2027-01-01'])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateDomain::class)->create(DomainInput::fromArray(['name' => 'ok.example', 'contact_id' => $client->id, 'expires_on' => '2020-01-01', 'registered_on' => '2021-01-01'])))->toThrow(ValidationException::class);

    $renamed = app(UpdateDomain::class)->update($domain->id, DomainInput::fromArray(['name' => 'bellavista.example', 'contact_id' => $client->id, 'expires_on' => '2026-06-30', 'registrar' => 'Gandi', 'renewal_months' => 24]));
    expect($renamed->registrar)->toBe('Gandi')->and($renamed->renewalMonths)->toBe(24);
});

it('logs a renewal once per period and moves the expiry by whole months without overflow', function (): void {
    Event::fake([DomainRenewed::class]);
    $client = Studio::client();
    $account = Studio::account();
    $domain = Domain::factory()->create(['contact_id' => $client->id, 'expires_on' => '2028-02-29', 'renewal_cost_cents' => 1_500, 'account_id' => $account->id]);

    $first = app(LogDomainRenewal::class)->log($domain->id, CarbonImmutable::parse('2028-02-20'));
    $second = app(LogDomainRenewal::class)->log($domain->id);

    expect($first->amount->cents)->toBe(1_500)
        ->and($first->occurredOn->toDateString())->toBe('2028-02-20')
        ->and($first->allocation?->toString())->toBe("domain_renewal:{$domain->id}:2028-02-29")
        ->and($first->description)->toContain($domain->name)
        ->and($second->allocation?->toString())->toBe("domain_renewal:{$domain->id}:2029-02-28")
        ->and($domain->refresh()->expires_on->toDateString())->toBe('2030-02-28')
        ->and(Movement::query()->count())->toBe(2);
    Event::assertDispatchedTimes(DomainRenewed::class, 2);

    Movement::factory()->for($account)->out(1_500, '2030-02-20')->allocatedTo("domain_renewal:{$domain->id}:2030-02-28")->create();
    expect(app(LogDomainRenewal::class)->log($domain->id)->occurredOn->toDateString())->toBe('2030-02-20')->and(Movement::query()->count())->toBe(3);

    $free = Domain::factory()->create(['contact_id' => $client->id, 'renewal_cost_cents' => null]);
    expect(fn () => app(LogDomainRenewal::class)->log($free->id))->toThrow(ValidationException::class);
    $noAccount = Domain::factory()->create(['contact_id' => $client->id]);
    expect(fn () => app(LogDomainRenewal::class)->log($noAccount->id))->toThrow(ValidationException::class);
});

it('lists what expires, and contributes renewals to the projection with the overdue first', function (): void {
    $client = Studio::client();
    $late = Domain::factory()->create(['contact_id' => $client->id, 'name' => 'late.example', 'expires_on' => '2026-03-01']);
    $soon = Domain::factory()->create(['contact_id' => $client->id, 'name' => 'soon.example', 'expires_on' => '2026-04-10', 'renewal_months' => 1]);
    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'later.example', 'expires_on' => '2026-09-01']);
    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'gone.example', 'expires_on' => '2026-03-01', 'status' => DomainStatus::Released]);
    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'free.example', 'expires_on' => '2026-03-20', 'renewal_cost_cents' => 0]);
    $today = CarbonImmutable::parse('2026-03-15');

    $names = fn (array $domains): array => array_map(fn ($d): string => $d->name, $domains);
    expect($names(app(Domains::class)->expiringWithin($today, 30)))->toBe(['late.example', 'free.example', 'soon.example'])
        ->and($late->toData()->isExpired($today))->toBeTrue()
        ->and($soon->toData()->isExpired($today))->toBeFalse()
        ->and($soon->toData()->expiresWithin($today, 30))->toBeTrue()
        ->and($soon->toData()->expiresWithin($today, 10))->toBeFalse();

    $projection = app(CashFlowProjection::class)->project($today, 3);
    $flows = array_map(fn ($f): array => [$f->date->toDateString(), $f->description, $f->amount->cents], $projection->flows);
    expect($flows)->toBe([
        ['2026-03-01', 'Rinnovo dominio late.example', 1_500],
        ['2026-04-10', 'Rinnovo dominio soon.example', 1_500],
        ['2026-05-10', 'Rinnovo dominio soon.example', 1_500],
    ])->and($projection->isOverdue($projection->flows[0]))->toBeTrue();
});

it('changes status, follows a merged contact, and is managed from the panel', function (): void {
    signInAsOwner();
    $client = Studio::client();
    $account = Studio::account();

    $this->get('/admin/domains')->assertOk();
    $this->get('/admin/domains/create')->assertOk();

    Livewire::test(CreateDomainPage::class)
        ->fillForm(['name' => 'bellavista.example', 'contact_id' => $client->id, 'expires_on' => '2026-03-31', 'renewal_months' => 12, 'auto_renew' => true, 'renewal_cost' => '15', 'account_id' => $account->id])
        ->call('create')
        ->assertHasNoFormErrors();
    $domain = Domain::query()->sole();

    $this->get("/admin/domains/{$domain->id}")->assertOk()->assertSee('bellavista.example')->assertSee('Nessun rinnovo registrato');

    Livewire::test(EditDomain::class, ['record' => $domain->id])->assertSchemaStateSet(['renewal_cost' => '15.00'])->fillForm(['registrar' => 'Gandi'])->call('save')->assertHasNoFormErrors();
    expect($domain->refresh()->registrar)->toBe('Gandi');

    Livewire::test(ViewDomain::class, ['record' => $domain->id])->callAction('renewal', ['paid_on' => '2026-03-20', 'account_id' => $account->id])->assertNotified();
    expect($domain->refresh()->expires_on->toDateString())->toBe('2027-03-31')->and(Movement::query()->count())->toBe(1);
    $this->get("/admin/domains/{$domain->id}")->assertOk()->assertSee('Rinnovo dominio bellavista.example');

    Livewire::test(ListDomains::class)->assertCanSeeTableRecords([$domain])->filterTable('expiring')->assertCanNotSeeTableRecords([$domain]);
    Livewire::test(DomainsExpiringWidget::class)->assertSee('Domini in scadenza entro 30 giorni');
    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Domini');
    Livewire::test(ContactDomainsTable::class, ['contactId' => $client->id])->assertSee('bellavista.example');

    $released = app(SetDomainStatus::class)->set($domain->id, DomainStatus::Released);
    expect($released->status)->toBe(DomainStatus::Released)->and($released->autoRenew)->toBeFalse();
    Livewire::test(ViewDomain::class, ['record' => $domain->id])->assertActionHidden('renewal');

    $other = Studio::client(['vat_number' => '12345678903', 'display_name' => 'Doppione', 'business_name' => 'Doppione']);
    app(MergeContacts::class)->merge($other->id, $client->id);
    expect(Domain::query()->findOrFail($domain->id)->contact_id)->toBe($other->id);
});
