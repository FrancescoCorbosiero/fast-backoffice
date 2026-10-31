<?php

declare(strict_types=1);

use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PaymentRequest;
use App\Modules\Quotes\Actions\AcceptQuote;
use App\Modules\Quotes\Actions\CreateQuote;
use App\Modules\Quotes\Actions\DeclineQuote;
use App\Modules\Quotes\Actions\DeleteQuote;
use App\Modules\Quotes\Actions\MarkQuoteSent;
use App\Modules\Quotes\Actions\RenderQuotePdf;
use App\Modules\Quotes\Actions\UpdateQuote;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Enums\AcceptanceOutcome;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Events\QuoteAccepted;
use App\Modules\Quotes\Events\QuoteDeclined;
use App\Modules\Quotes\Events\QuoteSent;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Queries\FindQuote;
use App\Modules\Quotes\Queries\Quotes;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Enums\RegimeFiscale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $extra
 */
function quote(int $contactId, array $lines, array $extra = []): QuoteData
{
    return app(CreateQuote::class)->create(QuoteInput::fromArray(['contact_id' => $contactId, 'lines' => $lines, ...$extra]));
}

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15');
});

it('writes a quote with its own number, thirty days of validity and the figures the engine computes', function (): void {
    $client = Studio::client();

    $quote = quote($client->id, [Studio::line('Sito vetrina', '1500.00'), Studio::line('Ore extra', '37.50', 0, null, '3.5')], ['subject' => 'Nuovo sito']);

    expect($quote->numberText)->toBe('PRV 1/2026')
        ->and($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->issuedOn->toDateString())->toBe('2026-03-15')
        ->and($quote->validUntil->toDateString())->toBe('2026-04-14')
        ->and($quote->customerName)->toBe('Pasticceria Bellavista S.r.l.')
        ->and($quote->subject)->toBe('Nuovo sito')
        ->and($quote->total->cents)->toBe(163_125)
        ->and($quote->totals->stampDutyCharged->isZero())->toBeTrue()
        ->and($quote->lines[1]->natura?->value)->toBe('N2.2')
        ->and($quote->title())->toBe('Preventivo PRV 1/2026')
        ->and($quote->reference()->toString())->toBe('quote:'.$quote->id);

    $second = quote($client->id, [Studio::line('Logo', '300.00')], ['issued_on' => '2026-03-01', 'valid_until' => '2026-03-20']);
    expect($second->numberText)->toBe('PRV 2/2026')->and($second->validUntil->toDateString())->toBe('2026-03-20');

    expect(Invoice::query()->count())->toBe(0);
});

it('refuses a validity that ends before the date, empty lines, or an unknown customer', function (): void {
    $client = Studio::client();

    expect(fn () => quote($client->id, [Studio::line('x', '1.00')], ['issued_on' => '2026-03-15', 'valid_until' => '2026-03-14']))->toThrow(ValidationException::class)
        ->and(fn () => quote($client->id, []))->toThrow(ValidationException::class)
        ->and(fn () => quote(999_999, [Studio::line('x', '1.00')]))->toThrow(ValidationException::class)
        ->and(fn () => quote($client->id, [Studio::line('Con IVA', '100.00', 2200)]))->toThrow(ValidationException::class)
        ->and(Quote::query()->count())->toBe(0);
});

it('shows as expired the day after its validity, while it stays open and can still be accepted late', function (): void {
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('x', '100.00')], ['valid_until' => '2026-03-31']);

    expect($quote->statusOn(CarbonImmutable::parse('2026-03-31')))->toBe(QuoteStatus::Draft)
        ->and($quote->isExpired(CarbonImmutable::parse('2026-03-31')))->toBeFalse()
        ->and($quote->statusOn(CarbonImmutable::parse('2026-04-01')))->toBe(QuoteStatus::Expired)
        ->and($quote->expiresWithin(CarbonImmutable::parse('2026-03-24'), 7))->toBeTrue()
        ->and($quote->expiresWithin(CarbonImmutable::parse('2026-03-23'), 7))->toBeFalse()
        ->and($quote->expiresWithin(CarbonImmutable::parse('2026-04-01'), 7))->toBeFalse();

    $accepted = app(AcceptQuote::class)->accept($quote->id, AcceptanceOutcome::InvoiceDraft, CarbonImmutable::parse('2026-04-10'));
    expect($accepted->statusOn(CarbonImmutable::parse('2026-04-10')))->toBe(QuoteStatus::Accepted)->and($accepted->isExpired(CarbonImmutable::parse('2026-04-10')))->toBeFalse();
});

it('lists open, expiring and expired quotes by comparing dates', function (): void {
    $client = Studio::client();
    $soon = quote($client->id, [Studio::line('a', '1.00')], ['valid_until' => '2026-03-20']);
    $later = quote($client->id, [Studio::line('b', '1.00')], ['valid_until' => '2026-04-30']);
    $gone = quote($client->id, [Studio::line('c', '1.00')], ['issued_on' => '2026-02-01', 'valid_until' => '2026-03-14']);
    $declined = quote($client->id, [Studio::line('d', '1.00')], ['valid_until' => '2026-03-16']);
    app(DeclineQuote::class)->decline($declined->id);
    $today = CarbonImmutable::parse('2026-03-15');

    $ids = fn (array $quotes): array => array_map(fn (QuoteData $q): int => $q->id, $quotes);
    $quotes = app(Quotes::class);

    expect($ids($quotes->open($today)))->toBe([$soon->id, $later->id])
        ->and($ids($quotes->expiringWithin($today, 7)))->toBe([$soon->id])
        ->and($ids($quotes->expired($today)))->toBe([$gone->id])
        ->and($ids($quotes->forContact($client->id)))->toBe([$declined->id, $later->id, $soon->id, $gone->id])
        ->and(app(FindQuote::class)->byId($gone->id)?->numberText)->toBe('PRV 3/2026');
});

it('is reworked while open; a sent quote goes back to draft and loses the PDF it was sent with', function (): void {
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('x', '100.00')]);
    $sent = app(MarkQuoteSent::class)->mark($quote->id, CarbonImmutable::parse('2026-03-16'));
    expect($sent->pdfFileId)->not->toBeNull();

    $updated = app(UpdateQuote::class)->update($quote->id, QuoteInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('y', '200.00')], 'valid_until' => '2026-05-01', 'notes' => 'rivisto']));

    expect($updated->status)->toBe(QuoteStatus::Draft)
        ->and($updated->numberText)->toBe('PRV 1/2026')
        ->and($updated->total->cents)->toBe(20_000)
        ->and($updated->validUntil->toDateString())->toBe('2026-05-01')
        ->and($updated->issuedOn->toDateString())->toBe('2026-03-15')
        ->and($updated->sentOn)->toBeNull()
        ->and($updated->pdfFileId)->toBeNull()
        ->and($updated->notes)->toBe('rivisto');

    app(DeclineQuote::class)->decline($quote->id, 'troppo caro');
    expect(fn () => app(UpdateQuote::class)->update($quote->id, QuoteInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('z', '1.00')]])))->toThrow(LogicException::class);
});

it('keeps the PDF as sent, with the studio\'s layout, and records who was told what', function (): void {
    Event::fake([QuoteSent::class]);
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('Sito vetrina', '1500.00')], ['subject' => 'Nuovo sito']);

    $sent = app(MarkQuoteSent::class)->mark($quote->id, CarbonImmutable::parse('2026-03-16'));

    expect($sent->status)->toBe(QuoteStatus::Sent)
        ->and($sent->sentOn?->toDateString())->toBe('2026-03-16')
        ->and(app(FilesOf::class)->get($sent->reference(), 'pdf'))->toHaveCount(1)
        ->and(substr(app(ReadFile::class)->contents($sent->pdfFileId ?? 0), 0, 4))->toBe('%PDF')
        ->and(substr(app(RenderQuotePdf::class)->render($sent), 0, 4))->toBe('%PDF')
        ->and(RenderQuotePdf::fileName($sent))->toBe('preventivo-1-2026.pdf')
        ->and(app(AuditTrail::class)->forSubject($sent->reference())[0]->action)->toBe('quotes.quote.sent')
        ->and(fn () => app(MarkQuoteSent::class)->mark($quote->id))->toThrow(LogicException::class);
    Event::assertDispatched(QuoteSent::class);
});

it('accepted, becomes an invoice draft through Invoicing in the same transaction', function (): void {
    Event::fake([QuoteAccepted::class]);
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('Sito vetrina', '1500.00'), Studio::line('Hosting', '120.00')], ['subject' => 'Nuovo sito']);
    app(MarkQuoteSent::class)->mark($quote->id);

    $accepted = app(AcceptQuote::class)->accept($quote->id, AcceptanceOutcome::InvoiceDraft, CarbonImmutable::parse('2026-03-20'));

    expect($accepted->status)->toBe(QuoteStatus::Accepted)
        ->and($accepted->decidedOn?->toDateString())->toBe('2026-03-20')
        ->and($accepted->invoiceId)->not->toBeNull()
        ->and($accepted->paymentRequestId)->toBeNull();

    $invoice = Invoice::query()->findOrFail($accepted->invoiceId)->toData();
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->contactId)->toBe($client->id)
        ->and(array_map(fn ($l) => $l->description, $invoice->lines))->toBe(['Sito vetrina', 'Hosting'])
        ->and($invoice->notes)->toBe('Rif. Preventivo PRV 1/2026: Nuovo sito');

    Event::assertDispatched(QuoteAccepted::class, fn (QuoteAccepted $e): bool => $e->invoiceId === $accepted->invoiceId && $e->outcome === AcceptanceOutcome::InvoiceDraft);
    expect(fn () => app(AcceptQuote::class)->accept($quote->id, AcceptanceOutcome::InvoiceDraft))->toThrow(LogicException::class)
        ->and(fn () => app(DeclineQuote::class)->decline($quote->id))->toThrow(LogicException::class)
        ->and(array_map(fn ($entry) => $entry->action, app(AuditTrail::class)->forSubject($accepted->reference())))->toContain('quotes.quote.accepted');
});

it('accepted, becomes a payment request dated on the acceptance', function (): void {
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('Acconto sito', '500.00')]);

    $accepted = app(AcceptQuote::class)->accept($quote->id, AcceptanceOutcome::PaymentRequest, CarbonImmutable::parse('2026-03-18'));

    $request = PaymentRequest::query()->findOrFail($accepted->paymentRequestId)->toData();
    expect($accepted->invoiceId)->toBeNull()
        ->and($request->numberText)->toBe('RP 1/2026')
        ->and($request->issuedOn->toDateString())->toBe('2026-03-18')
        ->and($request->total->cents)->toBe(50_000)
        ->and($request->contactId)->toBe($client->id)
        ->and(Invoice::query()->count())->toBe(0);
});

it('leaves nothing behind when Invoicing refuses the document', function (): void {
    // Quoted under the ordinary regime with VAT; by the time it's accepted the studio is flat-rate, and VAT lines are refused.
    Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: null));
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('x', '100.00', 2200)]);
    Studio::configure();

    expect(fn () => app(AcceptQuote::class)->accept($quote->id, AcceptanceOutcome::PaymentRequest))->toThrow(ValidationException::class);

    expect(Quote::query()->findOrFail($quote->id)->status)->toBe(QuoteStatus::Draft)
        ->and(PaymentRequest::query()->count())->toBe(0);
});

it('declined, keeps the reason and its number; only drafts are deleted', function (): void {
    Event::fake([QuoteDeclined::class]);
    $client = Studio::client();
    $quote = quote($client->id, [Studio::line('x', '100.00')]);
    $other = quote($client->id, [Studio::line('y', '100.00')]);

    $declined = app(DeclineQuote::class)->decline($quote->id, '  Troppo caro  ', CarbonImmutable::parse('2026-03-19'));

    expect($declined->status)->toBe(QuoteStatus::Declined)
        ->and($declined->declineReason)->toBe('Troppo caro')
        ->and($declined->decidedOn?->toDateString())->toBe('2026-03-19')
        ->and($declined->statusOn(CarbonImmutable::parse('2027-01-01')))->toBe(QuoteStatus::Declined);
    Event::assertDispatched(QuoteDeclined::class);

    expect(fn () => app(DeleteQuote::class)->delete($quote->id))->toThrow(LogicException::class);

    app(DeleteQuote::class)->delete($other->id);
    expect(Quote::query()->count())->toBe(1)
        ->and(quote($client->id, [Studio::line('z', '1.00')])->numberText)->toBe('PRV 3/2026');
});

it('follows a merged contact', function (): void {
    $keep = Studio::client();
    $gone = Studio::client(['vat_number' => '12345678903', 'display_name' => 'Doppione', 'business_name' => 'Doppione']);
    $quote = quote($gone->id, [Studio::line('x', '1.00')]);

    app(MergeContacts::class)->merge($keep->id, $gone->id);

    expect(Quote::query()->findOrFail($quote->id)->contact_id)->toBe($keep->id);
});
