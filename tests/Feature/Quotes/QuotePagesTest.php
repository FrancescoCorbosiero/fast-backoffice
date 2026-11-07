<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Service;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Quotes\Actions\CreateQuote as CreateQuoteAction;
use App\Modules\Quotes\Actions\DeclineQuote;
use App\Modules\Quotes\Contributions\QuotesContactPanel;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\EditQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Modules\Quotes\Filament\Widgets\ContactQuotesTable;
use App\Modules\Quotes\Filament\Widgets\QuotesOverviewWidget;
use App\Modules\Quotes\Models\Quote;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-15');
});

it('renders the quote screens', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/quotes', '/admin/quotes/create']);

it('points an empty listino out on the line picker, with a way to fill it', function (): void {
    Livewire::test(CreateQuote::class)->assertSee('Il listino è vuoto')->assertSee('/admin/catalog');

    Service::factory()->create(['name' => 'Hosting annuale']);

    Livewire::test(CreateQuote::class)->assertDontSee('Il listino è vuoto');
});

it('writes, edits, sends and accepts a quote from the panel', function (): void {
    $client = Studio::client();

    Livewire::test(CreateQuote::class)
        ->fillForm([
            'contact_id' => $client->id,
            'issued_on' => '2026-03-15',
            'valid_until' => '2026-04-15',
            'subject' => 'Nuovo sito',
            'lines' => [['description' => 'Sito vetrina', 'quantity' => '1', 'unit_price' => '1500', 'vat_percent' => '0', 'natura' => null]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::query()->sole();
    expect($quote->number_text)->toBe('PRV 1/2026')->and($quote->total_cents)->toBe(150_000);

    $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee('PRV 1/2026')->assertSee('Nuovo sito');

    Livewire::test(EditQuote::class, ['record' => $quote->id])
        ->assertSchemaStateSet(['subject' => 'Nuovo sito'])
        ->fillForm(['lines' => [['description' => 'Sito vetrina', 'quantity' => '1', 'unit_price' => '1200', 'vat_percent' => '0', 'natura' => null]]])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($quote->refresh()->total_cents)->toBe(120_000);

    $this->get("/admin/quotes/{$quote->id}")->assertOk()
        ->assertSee("/admin/contacts/{$client->id}")
        ->assertSee('mailto:'.$client->email);

    $page = Livewire::test(ViewQuote::class, ['record' => $quote->id])
        ->assertActionHasLabel('pdf', 'Scarica PDF')
        ->assertActionHidden('edit-sent')
        ->callAction('sent', ['sent_on' => '2026-03-16'])
        ->assertNotified()
        // Sent: the PDF offered is the one the customer got; editing warns; the validity can move on its own.
        ->assertActionHasLabel('pdf', 'PDF inviato')
        ->assertActionVisible('edit-sent')
        ->callAction('edit-sent')
        ->assertRedirect("/admin/quotes/{$quote->id}/edit")
        ->callAction('extend', ['valid_until' => '2026-05-15'])
        ->assertNotified();
    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent)->and($quote->valid_until->toDateString())->toBe('2026-05-15');

    $page->callAction('accept', ['outcome' => 'invoice_draft', 'accepted_on' => '2026-03-20'])
        ->assertNotified()
        ->assertRedirect('/admin/invoices/'.Invoice::query()->sole()->id);

    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($quote->invoice_id)->toBe(Invoice::query()->sole()->id);

    $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee('Accettato')->assertSee("/admin/quotes/create?from={$quote->id}");
    $this->get("/admin/quotes/{$quote->id}/edit")->assertForbidden();
});

it('duplicates an answered quote into a new one for the same client with the same lines', function (): void {
    $client = Studio::client();
    $source = app(CreateQuoteAction::class)->create(QuoteInput::fromArray(['contact_id' => $client->id, 'subject' => 'Restyling', 'notes' => 'Sconto fedeltà', 'lines' => [Studio::line('Restyling', '800.00'), Studio::line('Hosting', '120.00')]]));
    app(DeclineQuote::class)->decline($source->id);
    $quote = Quote::query()->findOrFail($source->id);

    Livewire::test(ViewQuote::class, ['record' => $quote->id])->assertActionVisible('duplicate');

    Livewire::withQueryParams(['from' => (string) $quote->id])
        ->test(CreateQuote::class)
        ->assertSchemaStateSet(['contact_id' => $client->id, 'subject' => 'Restyling', 'notes' => 'Sconto fedeltà', 'issued_on' => '2026-03-15'])
        ->call('create')
        ->assertHasNoFormErrors();

    $copy = Quote::query()->whereKeyNot($quote->id)->sole();
    expect($copy->number_text)->toBe('PRV 2/2026')
        ->and($copy->status)->toBe(QuoteStatus::Draft)
        ->and($copy->total_cents)->toBe($quote->total_cents)
        ->and($copy->lines)->toBe($quote->lines)
        ->and($copy->valid_until->toDateString())->toBe('2026-04-14');
});

it('declines, deletes and downloads from the view page', function (): void {
    $client = Studio::client();
    $quote = Quote::factory()->create(['contact_id' => $client->id]);
    $draft = Quote::factory()->create(['contact_id' => $client->id]);

    Livewire::test(ViewQuote::class, ['record' => $quote->id])
        ->callAction('pdf')
        ->assertFileDownloaded('preventivo-'.$quote->number.'-2026.pdf')
        ->callAction('decline', ['declined_on' => '2026-03-18', 'reason' => 'Ha scelto un altro fornitore'])
        ->assertNotified();
    expect($quote->refresh()->status)->toBe(QuoteStatus::Declined)->and($quote->decline_reason)->toBe('Ha scelto un altro fornitore');

    Livewire::test(ViewQuote::class, ['record' => $draft->id])->callAction('delete')->assertRedirect();
    expect(Quote::query()->count())->toBe(1);
});

it('shows the derived status in the list and on the contact page', function (): void {
    $client = Studio::client();
    Quote::factory()->create(['contact_id' => $client->id, 'valid_until' => '2026-03-10', 'subject' => 'Vecchio']);
    Quote::factory()->create(['contact_id' => $client->id, 'valid_until' => '2026-03-20', 'subject' => 'Imminente']);

    $expired = Quote::query()->where('subject', 'Vecchio')->get();
    $expiring = Quote::query()->where('subject', 'Imminente')->get();

    // The list opens on the open quotes; the tabs say how many wait, expire or expired.
    Livewire::test(ListQuotes::class)->assertSee('Bozza')
        ->assertCanSeeTableRecords($expiring)->assertCanNotSeeTableRecords($expired)
        ->filterTable('expiring')->assertCanSeeTableRecords($expiring)->assertCanNotSeeTableRecords($expired)
        ->resetTableFilters()
        ->set('activeTab', ListQuotes::TAB_EXPIRED)->assertSee('Scaduto')->assertCanSeeTableRecords($expired)->assertCanNotSeeTableRecords($expiring)
        ->set('activeTab', ListQuotes::TAB_ALL)->assertCanSeeTableRecords([...$expired, ...$expiring])
        ->filterTable('status', 'expired')->assertCanSeeTableRecords($expired)->assertCanNotSeeTableRecords($expiring);

    Livewire::test(QuotesOverviewWidget::class)
        ->assertSee('Preventivi aperti')->assertSee('Scaduti senza risposta')
        ->assertSee('activeTab='.ListQuotes::TAB_EXPIRING, escape: false)->assertSee('activeTab='.ListQuotes::TAB_EXPIRED, escape: false);

    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Preventivi');
    Livewire::test(ContactQuotesTable::class, ['contactId' => $client->id])->assertSee('PRV 1/2026')->assertSee('Scaduto');
});

it('opens the quote form already knowing the client from the contact page', function (): void {
    $client = Studio::client();

    $url = QuotesContactPanel::newQuote($client->id)->getUrl();
    expect($url)->toBe(CreateQuote::getUrl(['contact_id' => $client->id]))->toContain("contact_id={$client->id}");
    expect((new QuotesContactPanel)->createAction($client->id))->not->toBeNull();

    $this->get("/admin/quotes/create?contact_id={$client->id}")->assertOk();

    Livewire::withQueryParams(['contact_id' => (string) $client->id])
        ->test(CreateQuote::class)
        ->assertSchemaStateSet(['contact_id' => $client->id, 'issued_on' => '2026-03-15']);
});

it('offers a new quote from the contact page, with or without quotes', function (): void {
    $client = Studio::client();

    Livewire::test(ContactQuotesTable::class, ['contactId' => $client->id])
        ->assertSee('Preventivi')
        ->assertSee('Nessun preventivo')
        ->assertSee('bozza di fattura')
        ->assertSee('Nuovo preventivo')
        ->assertSee("contact_id={$client->id}", escape: false);

    Quote::factory()->create(['contact_id' => $client->id, 'subject' => 'Restyling']);

    Livewire::test(ContactQuotesTable::class, ['contactId' => $client->id])
        ->assertSee('Restyling')
        ->assertDontSee('Nessun preventivo')
        ->assertSee('Nuovo preventivo')
        ->assertSee("contact_id={$client->id}", escape: false);
});
