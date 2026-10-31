<?php

declare(strict_types=1);

use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Invoicing\Actions\IssueInvoice;
use App\Modules\Invoicing\Actions\ProcessPecMessage;
use App\Modules\Invoicing\Actions\SendToSdi;
use App\Modules\Invoicing\Actions\UpdateDraft;
use App\Modules\Invoicing\Actions\VoidInvoice;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Events\InvoiceDelivered;
use App\Modules\Invoicing\Events\InvoiceNotDelivered;
use App\Modules\Invoicing\Events\InvoiceRejected;
use App\Modules\Invoicing\Events\PecMessageNeedsReview;
use App\Modules\Invoicing\Integrations\Pec\IncomingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use App\Modules\Invoicing\Jobs\ReadPecInbox;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PecMessage;
use App\Modules\Invoicing\Models\SupplierInvoice;
use App\Modules\Invoicing\Queries\PecMessages;
use App\Modules\Invoicing\Queries\SdiTransmissions;
use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Data\SdiSettings;
use App\Modules\Settings\Queries\GetSdiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;
use Tests\Support\FakePecMailbox;
use Tests\Support\PecFixtures;

function inbox(): FakePecMailbox
{
    $mailbox = new FakePecMailbox;
    app()->instance(PecMailbox::class, $mailbox);
    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, false, true));

    return $mailbox;
}

function sentInvoice(FakePecMailbox $mailbox, string $on = '2026-03-10'): InvoiceData
{
    $client = Contact::query()->where('vat_number', '04827193048')->first() ?? Studio::client();
    $invoice = Studio::issued($client, [Studio::line('Sito', '1000.00')], $on);

    return app(SendToSdi::class)->send($invoice->id);
}

function process(string $raw): App\Modules\Invoicing\Data\PecMessageData
{
    return app(ProcessPecMessage::class)->process(new IncomingPecMessage('uid-'.bin2hex(random_bytes(3)), $raw));
}

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-12 10:00:00');
});

it('lands the provider\'s receipts on the outbox entry', function (): void {
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox);

    $accepted = process(PecFixtures::receipt('accettazione', 'fake-1@studio.test'));
    $delivered = process(PecFixtures::receipt('avvenuta-consegna', 'fake-1@studio.test'));
    $stranger = process(PecFixtures::receipt('accettazione', 'unknown@elsewhere.test'));

    $transmission = app(SdiTransmissions::class)->forInvoice($invoice->id)[0];
    expect($accepted->kind)->toBe(PecMessageKind::PecAcceptance)->and($accepted->outcome)->toBe(PecMessageOutcome::Processed)->and($accepted->invoiceId)->toBe($invoice->id)
        ->and($delivered->kind)->toBe(PecMessageKind::PecDelivery)
        ->and($transmission->acceptedAt?->toDateString())->toBe('2026-03-11')
        ->and($transmission->deliveredToSdiAt)->not->toBeNull()
        ->and($transmission->transportStatus())->toStartWith('consegnata a SdI')
        ->and($stranger->outcome)->toBe(PecMessageOutcome::Ignored)
        ->and(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Sent);
});

it('marks the invoice delivered on SdI\'s RC and learns SdI\'s reply address', function (): void {
    Event::fake([InvoiceDelivered::class]);
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox);
    $rc = PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_RC_001.xml', PecFixtures::rc('IT01234567897_00001.xml', '42'), 'application/xml']]);

    $result = process($rc);

    $after = Invoice::query()->findOrFail($invoice->id)->toData();
    expect($result->kind)->toBe(PecMessageKind::SdiDelivered)->and($result->outcome)->toBe(PecMessageOutcome::Processed)->and($result->invoiceId)->toBe($invoice->id)
        ->and($after->status)->toBe(InvoiceStatus::Delivered)
        ->and($after->sdiIdentifier)->toBe('42')
        ->and($after->sdiOutcomeAt?->toIso8601String())->toBe('2026-03-11T09:12:30+01:00')
        ->and(app(SdiTransmissions::class)->forInvoice($invoice->id)[0]->outcome)->toBe('RC')
        ->and(app(FilesOf::class)->get($after->reference(), 'sdi_notification'))->toHaveCount(1)
        ->and(app(FilesOf::class)->get($result->reference(), 'eml'))->toHaveCount(1)
        ->and(app(GetSdiSettings::class)->get()->sdiAddress)->toBe(PecFixtures::SDI_REPLY_ADDRESS);
    Event::assertDispatched(InvoiceDelivered::class);

    // The same message again, or the same notification in another message, changes nothing.
    expect(process($rc)->id)->toBe($result->id)
        ->and(PecMessage::query()->count())->toBe(1);
    process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_RC_001.xml', PecFixtures::rc('IT01234567897_00001.xml', '42'), 'application/xml']]));
    expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Delivered)->and(PecMessage::query()->count())->toBe(2);
});

it('rejects on NS with the errors verbatim; the invoice is corrected and reissued within five days with the same number and date', function (): void {
    Event::fake([InvoiceRejected::class]);
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox, '2026-03-10');
    $client = Contact::query()->findOrFail($invoice->contactId);

    $result = process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_NS_001.xml', PecFixtures::ns('IT01234567897_00001.xml', [['00404', 'Fattura duplicata']]), 'application/xml']]));

    $rejected = Invoice::query()->findOrFail($invoice->id)->toData();
    expect($result->kind)->toBe(PecMessageKind::SdiRejected)->and($result->note)->toBe('00404 — Fattura duplicata')
        ->and($rejected->status)->toBe(InvoiceStatus::Rejected)
        ->and($rejected->sdiErrors)->toBe([['code' => '00404', 'description' => 'Fattura duplicata', 'hint' => 'Correggere e reinviare']])
        ->and($rejected->rejectedAt?->toDateString())->toBe('2026-03-11')
        ->and($rejected->resendDeadline()?->toDateString())->toBe('2026-03-16')
        ->and($rejected->canBeResent(CarbonImmutable::parse('2026-03-16')))->toBeTrue()
        ->and($rejected->canBeResent(CarbonImmutable::parse('2026-03-17')))->toBeFalse()
        ->and(app(App\Modules\Invoicing\Queries\Invoices::class)->receivables())->toBe([]);
    Event::assertDispatched(InvoiceRejected::class);

    // Corrected: a rejected invoice counts as never issued, so its lines can change.
    $corrected = app(UpdateDraft::class)->update($invoice->id, DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('Sito corretto', '900.00')], 'payment_terms_days' => 30]));
    expect($corrected->numberText)->toBe('1/2026')->and($corrected->status)->toBe(InvoiceStatus::Rejected);

    $this->travelTo('2026-03-16 09:00:00');
    $reissued = app(IssueInvoice::class)->reissue($invoice->id);

    expect($reissued->status)->toBe(InvoiceStatus::Issued)
        ->and($reissued->numberText)->toBe('1/2026')
        ->and($reissued->issuedOn?->toDateString())->toBe('2026-03-10')
        ->and($reissued->dueOn?->toDateString())->toBe('2026-04-09')
        ->and($reissued->total->cents)->toBe(90_000)
        ->and($reissued->sdiFileName)->toBe('IT01234567897_00002.xml')
        ->and($reissued->sdiErrors)->toBe([])
        ->and($reissued->rejectedAt)->toBeNull()
        ->and($reissued->sentAt)->toBeNull()
        ->and(app(FilesOf::class)->get($reissued->reference(), 'xml'))->toHaveCount(2);

    $resent = app(SendToSdi::class)->send($invoice->id);
    expect($resent->status)->toBe(InvoiceStatus::Sent)->and($mailbox->sent[1]->attachmentName)->toBe('IT01234567897_00002.xml')
        ->and(app(SdiTransmissions::class)->forInvoice($invoice->id))->toHaveCount(2);

    // The next invoice takes the next number: the rejected one kept its place.
    expect(Studio::issued($client, [Studio::line('x', '1.00')], '2026-03-16')->numberText)->toBe('2/2026');
});

it('past the five days a rejected invoice can only be voided, and an issued one is never voided', function (): void {
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox);
    process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_NS_001.xml', PecFixtures::ns('IT01234567897_00001.xml', [['00301', 'IdFiscaleIVA del CedentePrestatore non valido']]), 'application/xml']]));

    $this->travelTo('2026-03-17 09:00:00');
    expect(fn () => app(IssueInvoice::class)->reissue($invoice->id))->toThrow(ValidationException::class);

    $voided = app(VoidInvoice::class)->void($invoice->id, 'finestra chiusa');
    expect($voided->status)->toBe(InvoiceStatus::Void)
        ->and(Invoice::query()->findOrFail($invoice->id)->voided_at)->not->toBeNull()
        ->and(app(App\Modules\Invoicing\Queries\Invoices::class)->receivables())->toBe([])
        ->and(array_map(fn ($e) => $e->action, app(AuditTrail::class)->forSubject($voided->reference())))->toContain('invoicing.invoice.voided');

    $other = sentInvoice($mailbox, '2026-03-17');
    expect(fn () => app(VoidInvoice::class)->void($other->id))->toThrow(LogicException::class)
        ->and(fn () => app(IssueInvoice::class)->reissue($other->id))->toThrow(LogicException::class);
});

it('marks the invoice not delivered on MC, still issued and owed; the AT only goes on record', function (): void {
    Event::fake([InvoiceNotDelivered::class]);
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox);

    process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_MC_001.xml', PecFixtures::mc('IT01234567897_00001.xml', '77'), 'application/xml']]));

    $after = Invoice::query()->findOrFail($invoice->id)->toData();
    expect($after->status)->toBe(InvoiceStatus::NotDelivered)->and($after->sdiIdentifier)->toBe('77')->and($after->outstanding()->cents)->toBe(100_000)
        ->and(app(App\Modules\Invoicing\Queries\Invoices::class)->receivables())->toHaveCount(1);
    Event::assertDispatched(InvoiceNotDelivered::class);

    $at = process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_AT_001.xml', PecFixtures::at('IT01234567897_00001.xml', '77'), 'application/xml']]));
    expect($at->kind)->toBe(PecMessageKind::SdiAttestation)->and($at->outcome)->toBe(PecMessageOutcome::Processed)
        ->and(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::NotDelivered)
        ->and(app(FilesOf::class)->get($after->reference(), 'sdi_notification'))->toHaveCount(2);
});

it('turns a supplier invoice from SdI into a payable, signed or not, once per SdI identifier', function (): void {
    inbox();

    $signed = process(PecFixtures::envelope('posta-certificata', [
        ['IT04827193048_0007A.xml.p7m', PecFixtures::supplierInvoiceP7m(), 'application/pkcs7-mime'],
        ['IT04827193048_0007A_MT_001.xml', PecFixtures::mt('IT04827193048_0007A.xml', '555001'), 'application/xml'],
    ]));

    $supplierInvoice = SupplierInvoice::query()->sole();
    expect($signed->kind)->toBe(PecMessageKind::SupplierInvoice)->and($signed->outcome)->toBe(PecMessageOutcome::Processed)
        ->and($signed->supplierInvoiceId)->toBe($supplierInvoice->id)->and($signed->sdiIdentifier)->toBe('555001')
        ->and($supplierInvoice->sdi_id)->toBe('555001')
        ->and($supplierInvoice->number)->toBe('2026/118')
        ->and($supplierInvoice->total_cents)->toBe(14_640)
        ->and($supplierInvoice->file_name)->toBe('IT04827193048_0007A.xml')
        ->and($supplierInvoice->supplier_name)->toBe('Hosting Esempio S.r.l.')
        ->and(Contact::query()->where('vat_number', '04827193048')->exists())->toBeTrue();

    $again = process(PecFixtures::envelope('posta-certificata', [
        ['IT04827193048_0007A.xml', PecFixtures::supplierInvoiceXml(), 'application/xml'],
        ['IT04827193048_0007A_MT_001.xml', PecFixtures::mt('IT04827193048_0007A.xml', '555001'), 'application/xml'],
    ]));
    expect($again->outcome)->toBe(PecMessageOutcome::Processed)->and($again->note)->toContain('già')->and(SupplierInvoice::query()->count())->toBe(1);

    $broken = process(PecFixtures::envelope('posta-certificata', [['IT04827193048_0007B.xml.p7m', 'garbage', 'application/pkcs7-mime']]));
    expect($broken->outcome)->toBe(PecMessageOutcome::Review)->and($broken->note)->toContain('firmato');
});

it('keeps and flags what it cannot handle: unknown attachments, unmatched notifications, transport errors, unreadable messages', function (): void {
    Event::fake([PecMessageNeedsReview::class]);
    $mailbox = inbox();
    sentInvoice($mailbox);

    $unknown = process(PecFixtures::plain('Newsletter', 'ciao'));
    $unmatched = process(PecFixtures::envelope('posta-certificata', [['IT01234567897_00099_RC_001.xml', PecFixtures::rc('IT01234567897_00099.xml'), 'application/xml']]));
    $error = process(PecFixtures::envelope('errore-consegna', [], 'fake-1@studio.test'));
    $garbage = process("not even\r\n\r\nan email");

    expect($unknown->kind)->toBe(PecMessageKind::Unknown)->and($unknown->outcome)->toBe(PecMessageOutcome::Review)->and($unknown->note)->toContain('Nessun allegato')
        ->and($unmatched->kind)->toBe(PecMessageKind::SdiDelivered)->and($unmatched->outcome)->toBe(PecMessageOutcome::Review)->and($unmatched->note)->toContain('Nessuna fattura')
        ->and($error->kind)->toBe(PecMessageKind::PecError)->and($error->outcome)->toBe(PecMessageOutcome::Review)
        ->and(app(SdiTransmissions::class)->forInvoice($error->invoiceId ?? 0)[0]->transportStatus())->toStartWith('errore PEC')
        ->and($garbage->outcome)->toBeIn([PecMessageOutcome::Review, PecMessageOutcome::Failed])
        ->and(app(PecMessages::class)->needingReview())->toHaveCount(4);
    Event::assertDispatchedTimes(PecMessageNeedsReview::class, 4);
});

it('reads the mailbox through the job, handles each message once and flags it', function (): void {
    $mailbox = inbox();
    $invoice = sentInvoice($mailbox);
    $mailbox->receive(PecFixtures::receipt('accettazione', 'fake-1@studio.test'));
    $mailbox->receive(PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_RC_001.xml', PecFixtures::rc('IT01234567897_00001.xml'), 'application/xml']]));
    $mailbox->receive(PecFixtures::plain('Spam'));

    $result = app(ReadPecInbox::class)->run();

    expect($result->succeeded())->toBeFalse()
        ->and($result->output)->toContain('ricevuta di consegna: Elaborato')->toContain('Altro: Da verificare')
        ->and($mailbox->processed)->toBe(['uid-1', 'uid-2', 'uid-3'])
        ->and(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Delivered)
        ->and(PecMessage::query()->count())->toBe(3);

    expect(app(ReadPecInbox::class)->run()->output)->toBe('Nessun messaggio nuovo.');

    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, false, false));
    expect(app(ReadPecInbox::class)->run()->output)->toContain('spenta');

    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, false, true));
    $mailbox->failWith = 'IMAP down';
    expect(app(ReadPecInbox::class)->run()->succeeded())->toBeFalse();
});
