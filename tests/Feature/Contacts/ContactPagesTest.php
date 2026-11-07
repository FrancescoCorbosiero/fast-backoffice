<?php

declare(strict_types=1);

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ViewContact;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\FindContact;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
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

it('splits the list into one tab per role, coloured by role, and filters by mailability', function (): void {
    $client = Contact::factory()->create(['display_name' => 'Client One']);
    $supplier = Contact::factory()->supplier()->withoutConsent()->create(['display_name' => 'Supplier One']);

    Livewire::test(ListContacts::class)
        ->assertSee('Tutti')
        ->assertSee('Clienti')
        ->assertSee('Fornitori')
        ->set('activeTab', ContactRole::Supplier->value)
        ->assertCanSeeTableRecords([$supplier])
        ->assertCanNotSeeTableRecords([$client])
        ->assertSeeHtml('fi-color-warning')
        ->set('activeTab', 'all')
        ->filterTable('mailable')
        ->assertCanSeeTableRecords([$client])
        ->assertCanNotSeeTableRecords([$supplier]);
});

it('welcomes a fresh install with what contacts are for and a way to create the first one', function (): void {
    Livewire::test(ListContacts::class)
        ->assertSee('Nessun contatto')
        ->assertSee('Da un contatto nascono preventivi, fatture e siti.')
        ->assertSee('Nuovo contatto');
});

it('hints on the form that a contact with the same email or VAT number already exists, with a link to it', function (): void {
    $existing = Contact::factory()->withVatNumber()->create(['display_name' => 'Mario Rossi', 'email' => 'mario@rossi.test']);

    Livewire::test(CreateContact::class)
        ->fillForm(['email' => 'Mario@Rossi.test'])
        ->assertSee('Esiste già: Mario Rossi')
        ->assertSeeHtml(ContactLink::url($existing->id))
        ->fillForm(['email' => null, 'vat_number' => '01234567897'])
        ->assertSee('Un altro contatto ha già questa partita IVA: Mario Rossi');

    Livewire::test(EditContact::class, ['record' => $existing->id])
        ->assertDontSee('Esiste già');
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
        ->assertHasNoFormErrors()
        ->assertRedirect(ContactLink::url($contact->id));

    expect($contact->refresh()->phone)->toBe('+39 333 1234567')
        ->and($contact->roles)->toBe(['client', 'partner']);
});

it('goes back where the owner came from after completing a contact opened with ?return=', function (): void {
    $contact = Contact::factory()->create();

    expect(ContactLink::editUrl($contact->id, '/admin/invoices/7/edit'))->toContain('return=');

    Livewire::withQueryParams(['return' => '/admin/invoices/7/edit'])
        ->test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['tax_code' => 'RSSMRA85T10A562S'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin/invoices/7/edit');

    Livewire::withQueryParams(['return' => 'https://elsewhere.test/'])
        ->test(EditContact::class, ['record' => $contact->id])
        ->call('save')
        ->assertRedirect(ContactLink::url($contact->id));
});

it('links email, phone and PEC on the contact page and says whether the contact can be invoiced', function (): void {
    $incomplete = Contact::factory()->suppressed('rimbalzo')->create(['email' => 'mario@rossi.test', 'phone' => '+39 333 123 4567', 'pec' => 'mario@pec.test']);

    $this->get("/admin/contacts/{$incomplete->id}")
        ->assertOk()
        ->assertSee('mailto:mario@rossi.test', escape: false)
        ->assertSee('tel:+393331234567', escape: false)
        ->assertSee('mailto:mario@pec.test', escape: false)
        ->assertSee('bloccata: rimbalzo')
        ->assertSee('Manca partita IVA o codice fiscale: non fatturabile')
        ->assertSee('Completa')
        ->assertSee(ContactLink::editUrl($incomplete->id), escape: false);

    $invoiceable = Contact::factory()->withVatNumber()->create(['pec' => 'studio@pec.test']);

    $this->get("/admin/contacts/{$invoiceable->id}")
        ->assertSee('Fatturabile: PEC studio@pec.test')
        ->assertDontSee('non fatturabile');
});

it('shows the contact page with the sections other modules contribute, and their create actions in the "Nuovo" menu', function (): void {
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
            return Section::make($this->label())->components([Text::make("Contenuto per il contatto {$contactId}")]);
        }

        public function createAction(int $contactId): ?Action
        {
            return Action::make('new-test-thing')->label('Cosa di prova')->url("/admin/test-things/create?contact_id={$contactId}");
        }
    });
    app()->tag(['tests.contact-panel'], ContactPanel::TAG);

    $this->get("/admin/contacts/{$contact->id}")
        ->assertOk()
        ->assertSee('Mario Rossi')
        ->assertSee('Sezione di prova')
        ->assertSee("Contenuto per il contatto {$contact->id}")
        ->assertSee('Nuovo')
        ->assertSee('Cosa di prova')
        ->assertSee("/admin/test-things/create?contact_id={$contact->id}", escape: false);
});

it('offers, for every section of the contact page that lists things the owner creates, a way to create one for that contact', function (): void {
    $contact = Contact::factory()->create(['display_name' => 'Mario Rossi']);

    /** @var iterable<ContactPanel> $panels */
    $panels = app()->tagged(ContactPanel::TAG);
    $withCreate = [];
    $withoutCreate = [];

    foreach ($panels as $panel) {
        $action = $panel->createAction($contact->id);
        $action === null ? $withoutCreate[] = $panel->key() : $withCreate[] = $panel->key();

        if ($action !== null && $action->getUrl() !== null) {
            expect($action->getUrl())->toContain("contact_id={$contact->id}");
        }
    }

    sort($withCreate);
    sort($withoutCreate);

    // Bookings come from Cal.com, campaigns go to many contacts, leads arrive from the website: those explain themselves instead.
    expect($withCreate)->toBe(['cash-movements', 'change-requests', 'domains', 'invoices', 'quotes', 'repositories', 'websites'])
        ->and($withoutCreate)->toBe(['bookings', 'campaigns', 'leads']);
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

it('offers a picker that searches contacts, says who can be invoiced, and creates one inline with the given roles or asks for them', function (): void {
    Contact::factory()->supplier()->create(['display_name' => 'Fornitore Uno', 'email' => 'uno@supplier.test']);
    $client = Contact::factory()->withVatNumber()->create(['display_name' => 'Cliente Uno', 'email' => null]);

    $picker = ContactPicker::make('supplier_id', [ContactRole::Supplier]);

    expect($picker->getName())->toBe('supplier_id')
        ->and($picker->getSearchResults('uno'))->toBe([Contact::query()->where('display_name', 'Fornitore Uno')->value('id') => 'Fornitore Uno · uno@supplier.test · senza identità fiscale']);

    $anyRole = ContactPicker::make('contact_id', []);
    expect($anyRole->getSearchResults('uno'))->toHaveCount(2)
        ->and($anyRole->getSearchResults('cliente'))->toBe([$client->id => 'Cliente Uno · P.IVA 01234567897']);

    $fieldNames = function (Select $select): array {
        $names = [];
        $walk = function (array $components) use (&$names, &$walk): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $names[] = $component->getName();
                } elseif ($component instanceof Section) {
                    $children = $component->getDefaultChildComponents();
                    $walk(is_array($children) ? $children : []);
                }
            }
        };
        $form = $select->getCreateOptionActionForm(Schema::make());
        $walk(is_array($form) ? $form : []);

        return $names;
    };

    expect($fieldNames($anyRole))->toContain('roles', 'tax_code', 'sdi_code', 'pec')
        ->and($fieldNames($picker))->not->toContain('roles');
});
