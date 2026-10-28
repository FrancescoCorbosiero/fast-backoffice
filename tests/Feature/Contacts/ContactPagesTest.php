<?php

declare(strict_types=1);

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ViewContact;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\FindContact;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    signInAsOwner();
});

it('lists live contacts in a constant number of queries', function (): void {
    Contact::factory()->count(3)->create();
    Contact::factory()->create(['display_name' => 'Merged away', 'merged_into_id' => Contact::query()->first()?->id]);

    Livewire::test(ListContacts::class)->assertCanSeeTableRecords(Contact::query()->live()->get())->assertDontSee('Merged away');

    DB::enableQueryLog();
    Livewire::test(ListContacts::class)->assertOk();
    $queries = count(DB::getQueryLog());

    Contact::factory()->count(30)->create();
    DB::flushQueryLog();
    Livewire::test(ListContacts::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($queries);
});

it('filters the list by role and by mailability', function (): void {
    $client = Contact::factory()->create(['display_name' => 'Client One']);
    $supplier = Contact::factory()->supplier()->withoutConsent()->create(['display_name' => 'Supplier One']);

    Livewire::test(ListContacts::class)
        ->filterTable('role', ContactRole::Supplier->value)
        ->assertCanSeeTableRecords([$supplier])
        ->assertCanNotSeeTableRecords([$client])
        ->resetTableFilters()
        ->filterTable('mailable')
        ->assertCanSeeTableRecords([$client])
        ->assertCanNotSeeTableRecords([$supplier]);
});

it('creates a contact from the form through the module action', function (): void {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'kind' => 'company',
            'business_name' => 'Studio Verdi Srl',
            'roles' => ['client'],
            'email' => 'info@verdi.test',
            'vat_country' => 'IT',
            'vat_number' => '01234567897',
            'street' => 'Via Roma',
            'postcode' => '20100',
            'city' => 'Milano',
            'province' => 'MI',
            'country' => 'IT',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $contact = Contact::query()->sole();

    expect($contact->display_name)->toBe('Studio Verdi Srl')
        ->and($contact->vat_number)->toBe('01234567897')
        ->and($contact->toData()->hasFiscalIdentity())->toBeTrue();
});

it('shows fiscal validation errors in the form', function (): void {
    Livewire::test(CreateContact::class)
        ->fillForm(['kind' => 'individual', 'last_name' => 'Rossi', 'roles' => ['client'], 'vat_country' => 'IT', 'vat_number' => '01234567890', 'tax_code' => 'NOPE', 'country' => 'IT'])
        ->call('create')
        ->assertHasFormErrors(['vat_number', 'tax_code']);

    expect(Contact::query()->exists())->toBeFalse();
});

it('edits a contact through the module action', function (): void {
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['phone' => '+39 333 1234567', 'roles' => ['client', 'partner']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh()->phone)->toBe('+39 333 1234567')
        ->and($contact->roles)->toBe(['client', 'partner']);
});

it('shows the contact page with the sections other modules contribute', function (): void {
    $contact = Contact::factory()->create(['display_name' => 'Mario Rossi']);
    app()->instance('tests.contact-panel', new class implements ContactPanel
    {
        public function key(): string
        {
            return 'tests';
        }

        public function label(): string
        {
            return 'Sezione di prova';
        }

        public function sort(): int
        {
            return 10;
        }

        public function component(int $contactId): Component
        {
            return Text::make("Contenuto per il contatto {$contactId}");
        }
    });
    app()->tag(['tests.contact-panel'], ContactPanel::TAG);

    $this->get("/admin/contacts/{$contact->id}")
        ->assertOk()
        ->assertSee('Mario Rossi')
        ->assertSee('Sezione di prova')
        ->assertSee("Contenuto per il contatto {$contact->id}");
});

it('blocks and unblocks email, toggles consent and merges from the contact page', function (): void {
    $contact = Contact::factory()->withoutConsent()->create(['phone' => null]);
    $duplicate = Contact::factory()->create(['phone' => '999']);
    $find = app(FindContact::class);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('suppress', ['reason' => 'rimbalzo'])
        ->assertNotified();
    expect($find->byId($contact->id)?->emailSuppressionReason)->toBe('rimbalzo');

    Livewire::test(ViewContact::class, ['record' => $contact->id])->callAction('lift');
    expect($find->byId($contact->id)?->isEmailSuppressed())->toBeFalse();

    Livewire::test(ViewContact::class, ['record' => $contact->id])->callAction('consent');
    expect($find->byId($contact->id)?->emailConsent)->toBeTrue();

    Livewire::test(ViewContact::class, ['record' => $contact->id])->callAction('merge', ['duplicate_id' => $duplicate->id]);
    expect($find->byId($duplicate->id))->toBeNull()
        ->and($find->byId($contact->id)?->phone)->toBe('999');

    $this->get("/admin/contacts/{$duplicate->id}")->assertNotFound();
});

it('offers a picker that searches contacts and creates one inline with the given roles', function (): void {
    Contact::factory()->supplier()->create(['display_name' => 'Fornitore Uno', 'email' => 'uno@supplier.test']);
    Contact::factory()->create(['display_name' => 'Cliente Uno']);

    $picker = ContactPicker::make('supplier_id', [ContactRole::Supplier]);

    expect($picker->getName())->toBe('supplier_id')
        ->and($picker->getSearchResults('uno'))->toBe([Contact::query()->where('display_name', 'Fornitore Uno')->value('id') => 'Fornitore Uno · uno@supplier.test']);

    $anyRole = ContactPicker::make('contact_id', []);
    expect($anyRole->getSearchResults('uno'))->toHaveCount(2);
});
