<?php

declare(strict_types=1);

use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Leads\Actions\ConvertLead;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\MoveLead;
use App\Modules\Leads\Actions\UpdateLead;
use App\Modules\Leads\Data\ConversionInput;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Events\LeadConverted;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Queries\Leads;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15 10:00:00');
});

it('tracks a lead through the pipeline, with its follow-up', function (): void {
    $lead = app(CreateLead::class)->create(LeadInput::fromArray(['name' => 'Giulia Verdi', 'company' => 'Fioreria Verdi', 'email' => 'Giulia@Verdi.example', 'estimated_value' => '1.500,00', 'next_follow_up_on' => '2026-03-14', 'source' => 'fiera']));

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->email)->toBe('giulia@verdi.example')
        ->and($lead->estimatedValue?->cents)->toBe(150_000)
        ->and($lead->title())->toBe('Giulia Verdi · Fioreria Verdi')
        ->and($lead->isFollowUpDue(CarbonImmutable::parse('2026-03-15')))->toBeTrue()
        ->and($lead->isFollowUpDue(CarbonImmutable::parse('2026-03-13')))->toBeFalse();

    $contacted = app(MoveLead::class)->move($lead->id, LeadStatus::Contacted, nextFollowUpOn: CarbonImmutable::parse('2026-03-25'));
    expect($contacted->status)->toBe(LeadStatus::Contacted)->and($contacted->nextFollowUpOn?->toDateString())->toBe('2026-03-25');

    $updated = app(UpdateLead::class)->update($lead->id, LeadInput::fromArray(['name' => 'Giulia Verdi', 'company' => 'Fioreria Verdi SRL', 'notes' => 'vuole un e-commerce']));
    expect($updated->company)->toBe('Fioreria Verdi SRL')->and($updated->status)->toBe(LeadStatus::Contacted);

    $lost = app(MoveLead::class)->move($lead->id, LeadStatus::Lost, 'Budget insufficiente');
    expect($lost->status)->toBe(LeadStatus::Lost)->and($lost->lostReason)->toBe('Budget insufficiente')->and($lost->nextFollowUpOn)->toBeNull();

    $reopened = app(MoveLead::class)->move($lead->id, LeadStatus::Qualified);
    expect($reopened->status)->toBe(LeadStatus::Qualified)->and($reopened->lostReason)->toBeNull();

    expect(fn () => app(MoveLead::class)->move($lead->id, LeadStatus::Won))->toThrow(LogicException::class)
        ->and(fn () => app(CreateLead::class)->create(LeadInput::fromArray(['name' => '', 'email' => 'x'])))->toThrow(ValidationException::class);
});

it('lists the pipeline and the follow-ups due', function (): void {
    Lead::factory()->create(['name' => 'A', 'next_follow_up_on' => '2026-03-10']);
    Lead::factory()->create(['name' => 'B', 'status' => LeadStatus::Contacted, 'next_follow_up_on' => '2026-03-15']);
    Lead::factory()->create(['name' => 'C', 'status' => LeadStatus::Qualified, 'next_follow_up_on' => '2026-03-20']);
    Lead::factory()->create(['name' => 'D', 'status' => LeadStatus::Lost, 'next_follow_up_on' => '2026-03-01']);
    Lead::factory()->create(['name' => 'E', 'status' => LeadStatus::Won]);

    $leads = app(Leads::class);
    expect($leads->pipeline())->toBe(['new' => 1, 'contacted' => 1, 'qualified' => 1, 'won' => 1, 'lost' => 1])
        ->and(array_map(fn ($l) => $l->name, $leads->open()))->toBe(['A', 'B', 'C'])
        ->and(array_map(fn ($l) => $l->name, $leads->followUpsDue(CarbonImmutable::parse('2026-03-15'))))->toBe(['A', 'B']);
});

it('converts a lead into a new company contact, a quote and a website in one transaction', function (): void {
    Event::fake([LeadConverted::class]);
    $lead = Lead::factory()->create(['notes' => 'Vuole un sito nuovo']);

    $result = app(ConvertLead::class)->convert($lead->id, new ConversionInput(
        quoteLines: [LineInput::fromArray(Studio::line('Sito vetrina', '1500.00'))],
        quoteSubject: 'Nuovo sito',
        quoteValidUntil: CarbonImmutable::parse('2026-04-30'),
        websiteName: 'Fioreria Verdi',
        websiteUrl: 'https://www.fioreriaverdi.example',
    ));

    $contact = Contact::query()->findOrFail($result->contactId)->toData();
    expect($result->contactCreated)->toBeTrue()
        ->and($contact->kind)->toBe(ContactKind::Company)
        ->and($contact->displayName)->toBe('Fioreria Verdi')
        ->and($contact->email)->toBe('giulia@verdi.example')
        ->and($contact->hasRole(ContactRole::Client))->toBeTrue()
        ->and($contact->notes)->toContain('Vuole un sito nuovo');

    $quote = Quote::query()->findOrFail($result->quoteId)->toData();
    expect($quote->contactId)->toBe($contact->id)->and($quote->subject)->toBe('Nuovo sito')->and($quote->total->cents)->toBe(150_000)->and($quote->validUntil->toDateString())->toBe('2026-04-30');

    $website = Website::query()->findOrFail($result->websiteId)->toData();
    expect($website->contactId)->toBe($contact->id)->and($website->host)->toBe('www.fioreriaverdi.example');

    expect($result->lead->status)->toBe(LeadStatus::Won)
        ->and($result->lead->convertedAt)->not->toBeNull()
        ->and($result->lead->contactId)->toBe($contact->id)
        ->and($result->lead->quoteId)->toBe($quote->id)
        ->and($result->lead->websiteId)->toBe($website->id)
        ->and(app(Leads::class)->forContact($contact->id))->toHaveCount(1)
        ->and(array_map(fn ($e) => $e->action, app(AuditTrail::class)->forSubject($result->lead->reference())))->toContain('leads.lead.converted');
    Event::assertDispatched(LeadConverted::class, fn (LeadConverted $e): bool => $e->quoteId === $quote->id && $e->websiteId === $website->id);

    expect(fn () => app(ConvertLead::class)->convert($lead->id, new ConversionInput))->toThrow(LogicException::class)
        ->and(fn () => app(UpdateLead::class)->update($lead->id, LeadInput::fromArray(['name' => 'x'])))->not->toThrow(LogicException::class);
});

it('converts onto an existing contact as an individual, with nothing else, and leaves nothing behind when a part fails', function (): void {
    $client = Studio::client();
    $lead = Lead::factory()->create(['company' => null, 'name' => 'Marco Bianchi']);

    $result = app(ConvertLead::class)->convert($lead->id, new ConversionInput(existingContactId: $client->id));
    expect($result->contactCreated)->toBeFalse()->and($result->contactId)->toBe($client->id)->and($result->quoteId)->toBeNull()->and($result->websiteId)->toBeNull()
        ->and(Contact::query()->count())->toBe(1);

    $individual = Lead::factory()->create(['company' => null, 'name' => 'Anna Neri', 'email' => 'anna@neri.example']);
    $converted = app(ConvertLead::class)->convert($individual->id, new ConversionInput);
    $contact = Contact::query()->findOrFail($converted->contactId)->toData();
    expect($contact->kind)->toBe(ContactKind::Individual)->and($contact->firstName)->toBe('Anna')->and($contact->lastName)->toBe('Neri');

    $failing = Lead::factory()->create(['company' => 'Rotto', 'email' => 'rotto@example.test']);
    expect(fn () => app(ConvertLead::class)->convert($failing->id, new ConversionInput(websiteUrl: 'not a url')))->toThrow(ValidationException::class);
    expect(Lead::query()->findOrFail($failing->id)->status)->toBe(LeadStatus::New)
        ->and(Contact::query()->where('email', 'rotto@example.test')->exists())->toBeFalse()
        ->and(fn () => app(ConvertLead::class)->convert($failing->id, new ConversionInput(existingContactId: 999_999)))->toThrow(ValidationException::class);

    $other = Studio::client(['vat_number' => '12345678903', 'display_name' => 'Doppione', 'business_name' => 'Doppione']);
    app(MergeContacts::class)->merge($other->id, $client->id);
    expect(Lead::query()->findOrFail($lead->id)->contact_id)->toBe($other->id);
});
