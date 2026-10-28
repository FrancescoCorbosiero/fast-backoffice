<?php

declare(strict_types=1);

use App\Kernel\Fiscal\VatNumber;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Actions\CreateContact;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Contacts\Actions\SetEmailConsent;
use App\Modules\Contacts\Actions\SuppressEmail;
use App\Modules\Contacts\Actions\UpdateContact;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Events\ContactCreated;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Contacts\Events\ContactUpdated;
use App\Modules\Contacts\Events\EmailSuppressed;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Contacts\Queries\SearchContacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function contactInput(array $overrides = []): ContactInput
{
    return ContactInput::fromArray([
        'kind' => 'individual',
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'email' => 'Mario@Example.test',
        'roles' => ['client'],
        'street' => 'Via Roma',
        'street_number' => '1',
        'postcode' => '20100',
        'city' => 'Milano',
        'province' => 'mi',
        'country' => 'IT',
        ...$overrides,
    ]);
}

it('creates a contact, normalising what it stores, and records it', function (): void {
    Event::fake([ContactCreated::class]);

    $contact = app(CreateContact::class)->create(contactInput([
        'vat_number' => 'it 01234567897',
        'tax_code' => 'rssmra85t10a562s',
        'sdi_code' => 'abc1234',
        'pec' => 'Mario@PEC.test',
    ]));

    expect($contact->displayName)->toBe('Mario Rossi')
        ->and($contact->email)->toBe('mario@example.test')
        ->and($contact->vatNumber?->toString())->toBe('IT01234567897')
        ->and($contact->taxCode)->toBe('RSSMRA85T10A562S')
        ->and($contact->sdiCode)->toBe('ABC1234')
        ->and($contact->pec)->toBe('mario@pec.test')
        ->and($contact->address->province)->toBe('MI')
        ->and($contact->roles)->toBe([ContactRole::Client])
        ->and($contact->hasRole(ContactRole::Client))->toBeTrue()
        ->and($contact->hasFiscalIdentity())->toBeTrue()
        ->and($contact->reference()->toString())->toBe("contact:{$contact->id}")
        ->and(app(AuditTrail::class)->forSubject($contact->reference())[0]->action)->toBe('contacts.contact.created');

    Event::assertDispatched(ContactCreated::class, fn (ContactCreated $event): bool => $event->contactId === $contact->id);
});

it('names a company by its business name and a person by first and last name', function (): void {
    $company = app(CreateContact::class)->create(contactInput(['kind' => 'company', 'business_name' => 'Studio Verdi Srl', 'first_name' => null, 'last_name' => null]));

    expect($company->kind)->toBe(ContactKind::Company)
        ->and($company->displayName)->toBe('Studio Verdi Srl');
});

it('rejects invalid input with errors keyed by field', function (array $overrides, string $field): void {
    try {
        app(CreateContact::class)->create(contactInput($overrides));
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail('No ValidationException was thrown.');
})->with([
    'no name' => [['first_name' => null, 'last_name' => null], 'last_name'],
    'company without a business name' => [['kind' => 'company'], 'business_name'],
    'no role' => [['roles' => []], 'roles'],
    'bad email' => [['email' => 'nope'], 'email'],
    'bad pec' => [['pec' => 'nope'], 'pec'],
    'bad Italian VAT number' => [['vat_number' => '01234567890'], 'vat_number'],
    'bad German VAT number' => [['vat_country' => 'DE', 'vat_number' => '12'], 'vat_number'],
    'bad tax code' => [['tax_code' => 'RSSMRA85T10A562T'], 'tax_code'],
    'bad SdI code' => [['sdi_code' => '12345'], 'sdi_code'],
    'bad country' => [['country' => 'ITA'], 'country'],
]);

it('keeps one live contact per VAT number and per tax code', function (): void {
    app(CreateContact::class)->create(contactInput(['vat_number' => '01234567897', 'tax_code' => 'RSSMRA85T10A562S']));

    expect(fn () => app(CreateContact::class)->create(contactInput(['vat_number' => '01234567897'])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateContact::class)->create(contactInput(['tax_code' => 'RSSMRA85T10A562S'])))->toThrow(ValidationException::class);

    // The same number in another country is another identity.
    expect(app(CreateContact::class)->create(contactInput(['vat_country' => 'DE', 'vat_number' => '123456789', 'country' => 'DE']))->vatNumber?->country)->toBe('DE');
});

it('updates a contact, recording what changed, and lets it keep its own identifiers', function (): void {
    Event::fake([ContactUpdated::class]);
    $contact = app(CreateContact::class)->create(contactInput(['vat_number' => '01234567897']));

    $updated = app(UpdateContact::class)->update($contact->id, contactInput(['vat_number' => '01234567897', 'phone' => '+39 02 1234567', 'roles' => ['client', 'supplier']]));

    expect($updated->phone)->toBe('+39 02 1234567')
        ->and($updated->roles)->toBe([ContactRole::Client, ContactRole::Supplier])
        ->and(app(AuditTrail::class)->latest(1)[0]->details['changed'])->toContain('phone', 'roles');

    Event::assertDispatched(ContactUpdated::class);

    // Saving without changes records nothing.
    app(UpdateContact::class)->update($contact->id, contactInput(['vat_number' => '01234567897', 'phone' => '+39 02 1234567', 'roles' => ['client', 'supplier']]));
    expect(app(AuditTrail::class)->forSubject($contact->reference()))->toHaveCount(2);
});

it('tells who may receive email: an address, consent, no suppression', function (): void {
    expect(Contact::factory()->create()->toData()->isMailable())->toBeTrue()
        ->and(Contact::factory()->withoutConsent()->create()->toData()->isMailable())->toBeFalse()
        ->and(Contact::factory()->suppressed()->create()->toData()->isMailable())->toBeFalse()
        ->and(Contact::factory()->create(['email' => null])->toData()->isMailable())->toBeFalse();
});

it('suppresses email once, with its reason, and can lift it', function (): void {
    Event::fake([EmailSuppressed::class]);
    $contact = Contact::factory()->create();

    $suppressed = app(SuppressEmail::class)->suppress($contact->id, 'bounce');
    app(SuppressEmail::class)->suppress($contact->id, 'complaint');

    expect($suppressed->isEmailSuppressed())->toBeTrue()
        ->and($suppressed->emailSuppressionReason)->toBe('bounce')
        ->and(app(FindContact::class)->byId($contact->id)?->emailSuppressionReason)->toBe('bounce')
        ->and($suppressed->isMailable())->toBeFalse();

    Event::assertDispatchedTimes(EmailSuppressed::class, 1);

    $lifted = app(SuppressEmail::class)->lift($contact->id);
    expect($lifted->isEmailSuppressed())->toBeFalse()->and($lifted->isMailable())->toBeTrue();
});

it('records consent changes with their source', function (): void {
    $contact = Contact::factory()->withoutConsent()->create();

    expect(app(SetEmailConsent::class)->set($contact->id, true, 'signup form')->emailConsent)->toBeTrue()
        ->and(app(AuditTrail::class)->latest(1)[0]->details)->toEqualCanonicalizing(['consent' => true, 'source' => 'signup form']);

    app(SetEmailConsent::class)->set($contact->id, true, 'again');
    expect(app(AuditTrail::class)->forSubject($contact->toData()->reference()))->toHaveCount(1);
});

it('merges a duplicate: fills blanks, joins roles, keeps the safer email state, repoints through the event', function (): void {
    $keep = Contact::factory()->withoutConsent()->create(['phone' => null, 'vat_number' => null, 'notes' => 'kept']);
    $duplicate = Contact::factory()->supplier()->withVatNumber()->suppressed('bounce')->create(['phone' => '123', 'notes' => 'dup']);
    $repointed = [];
    Event::listen(ContactsMerged::class, function (ContactsMerged $event) use (&$repointed): void {
        $repointed[] = [$event->contactId, $event->mergedContactId, DB::transactionLevel() > 1];
    });

    $merged = app(MergeContacts::class)->merge($keep->id, $duplicate->id);

    expect($merged->phone)->toBe('123')
        ->and($merged->vatNumber?->toString())->toBe('IT01234567897')
        ->and($merged->roles)->toBe([ContactRole::Client, ContactRole::Supplier])
        ->and($merged->emailConsent)->toBeTrue()
        ->and($merged->isEmailSuppressed())->toBeTrue()
        ->and($merged->notes)->toBe("kept\n\ndup")
        ->and($repointed)->toBe([[$keep->id, $duplicate->id, true]])
        ->and(app(FindContact::class)->byId($duplicate->id))->toBeNull()
        ->and(app(FindContact::class)->resolve($duplicate->id)?->id)->toBe($keep->id)
        ->and(app(FindContact::class)->byFiscalIdentity(VatNumber::parse('IT01234567897'), null)?->id)->toBe($keep->id)
        ->and(Contact::query()->count())->toBe(2)
        ->and(app(AuditTrail::class)->latest(1)[0]->action)->toBe('contacts.contact.merged');

    expect(fn () => app(MergeContacts::class)->merge($keep->id, $duplicate->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => app(MergeContacts::class)->merge($keep->id, $keep->id))->toThrow(InvalidArgumentException::class);
});

it('finds contacts by id, by ids, by fiscal identity and by email', function (): void {
    $byVat = Contact::factory()->withVatNumber('01234567897')->create();
    $byTaxCode = Contact::factory()->withTaxCode('RSSMRA85T10A562S')->create(['email' => 'Someone@Example.test']);
    $find = app(FindContact::class);

    expect($find->byId($byVat->id)?->id)->toBe($byVat->id)
        ->and($find->byId(999_999))->toBeNull()
        ->and(array_keys($find->byIds([$byVat->id, $byTaxCode->id, 999_999])))->toBe([$byVat->id, $byTaxCode->id])
        ->and($find->byIds([]))->toBe([])
        ->and($find->byFiscalIdentity(VatNumber::parse('01234567897'), 'RSSMRA85T10A562S')?->id)->toBe($byVat->id)
        ->and($find->byFiscalIdentity(VatNumber::parse('00000000000'), 'rssmra85t10a562s')?->id)->toBe($byTaxCode->id)
        ->and($find->byFiscalIdentity(null, 'XXXXXX00X00X000X'))->toBeNull()
        ->and($find->byFiscalIdentity(null, null))->toBeNull()
        ->and($find->byEmail('someone@example.test')?->id)->toBe($byTaxCode->id);
});

it('searches by name, email, VAT number or tax code, optionally within a role', function (): void {
    Contact::factory()->company('Studio Verdi Srl')->withVatNumber('01234567897')->create(['email' => 'info@verdi.test']);
    Contact::factory()->supplier()->create(['display_name' => 'Anna Bianchi', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@bianchi.test']);
    Contact::factory()->create(['display_name' => 'Zeta', 'merged_into_id' => null, 'email' => null]);
    $search = app(SearchContacts::class);
    $names = fn (array $contacts): array => array_map(fn ($contact) => $contact->displayName, $contacts);

    expect($names($search->search('verdi')))->toBe(['Studio Verdi Srl'])
        ->and($names($search->search('bianchi.test')))->toBe(['Anna Bianchi'])
        ->and($names($search->search('0123456')))->toBe(['Studio Verdi Srl'])
        ->and($names($search->search('')))->toBe(['Anna Bianchi', 'Studio Verdi Srl', 'Zeta'])
        ->and($names($search->search('', ContactRole::Supplier)))->toBe(['Anna Bianchi'])
        ->and($names($search->withRole(ContactRole::Client)))->toBe(['Studio Verdi Srl', 'Zeta'])
        ->and($search->search('nobody'))->toBe([]);
});

it('routes invoices to SdI by code, PEC, portal or abroad', function (): void {
    $withCode = Contact::factory()->create(['sdi_code' => 'ABC1234', 'pec' => 'x@pec.test'])->toData()->sdiAddress();
    $withPec = Contact::factory()->create(['sdi_code' => null, 'pec' => 'x@pec.test'])->toData()->sdiAddress();
    $zeroCodeWithPec = Contact::factory()->create(['sdi_code' => '0000000', 'pec' => 'x@pec.test'])->toData()->sdiAddress();
    $consumer = Contact::factory()->create(['sdi_code' => null, 'pec' => null])->toData()->sdiAddress();
    $abroad = Contact::factory()->abroad()->create(['sdi_code' => 'ABC1234'])->toData();

    expect([$withCode->codiceDestinatario, $withCode->pec])->toBe(['ABC1234', null])
        ->and([$withPec->codiceDestinatario, $withPec->pec])->toBe(['0000000', 'x@pec.test'])
        ->and([$zeroCodeWithPec->codiceDestinatario, $zeroCodeWithPec->pec])->toBe(['0000000', 'x@pec.test'])
        ->and($consumer->isPortalOnly())->toBeTrue()
        ->and($abroad->isForeign())->toBeTrue()
        ->and($abroad->sdiAddress()->isAbroad())->toBeTrue()
        ->and($abroad->vatNumber?->country)->toBe('DE');
});
