<?php

declare(strict_types=1);

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\EditQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Modules\Quotes\Filament\Widgets\ContactQuotesTable;
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

    Livewire::test(ViewQuote::class, ['record' => $quote->id])
        ->callAction('sent', ['sent_on' => '2026-03-16'])
        ->assertNotified()
        ->callAction('accept', ['outcome' => 'invoice_draft', 'accepted_on' => '2026-03-20'])
        ->assertNotified();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($quote->invoice_id)->toBe(Invoice::query()->sole()->id);

    $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee('Accettato');
    $this->get("/admin/quotes/{$quote->id}/edit")->assertForbidden();
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

    Livewire::test(ListQuotes::class)->assertSee('Scaduto')->assertSee('Bozza')
        ->filterTable('expiring')->assertCanSeeTableRecords(Quote::query()->where('subject', 'Imminente')->get())->assertCanNotSeeTableRecords(Quote::query()->where('subject', 'Vecchio')->get())
        ->resetTableFilters()
        ->filterTable('status', 'expired')->assertCanSeeTableRecords(Quote::query()->where('subject', 'Vecchio')->get())->assertCanNotSeeTableRecords(Quote::query()->where('subject', 'Imminente')->get());

    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Preventivi');
    Livewire::test(ContactQuotesTable::class, ['contactId' => $client->id])->assertSee('PRV 1/2026')->assertSee('Scaduto');
});
