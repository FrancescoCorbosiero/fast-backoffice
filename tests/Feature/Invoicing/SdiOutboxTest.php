<?php

declare(strict_types=1);

use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Invoicing\Actions\SendToSdi;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\SendChannel;
use App\Modules\Invoicing\Events\InvoiceSent;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use App\Modules\Invoicing\Jobs\SendIssuedInvoices;
use App\Modules\Invoicing\Models\SdiTransmission;
use App\Modules\Invoicing\Queries\SdiTransmissions;
use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Data\SdiSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;
use Tests\Support\FakePecMailbox;

function pecChannel(bool $autoSend = false, bool $inbox = true): FakePecMailbox
{
    $mailbox = new FakePecMailbox;
    app()->instance(PecMailbox::class, $mailbox);
    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, $autoSend, $inbox));

    return $mailbox;
}

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-12 10:00:00');
});

it('sends one PEC message per file to SdI and records it in the outbox', function (): void {
    Event::fake([InvoiceSent::class]);
    $mailbox = pecChannel();
    $invoice = Studio::issued(Studio::client(), [Studio::line('Sito', '1000.00')], '2026-03-10');

    $sent = app(SendToSdi::class)->send($invoice->id);

    expect($sent->status)->toBe(InvoiceStatus::Sent)
        ->and($sent->sentChannel)->toBe(SendChannel::Pec)
        ->and($sent->sentAt?->toDateTimeString())->toBe('2026-03-12 10:00:00')
        ->and($sent->daysLeftToSend(Carbon\CarbonImmutable::today()))->toBeNull()
        ->and($mailbox->sent)->toHaveCount(1)
        ->and($mailbox->sent[0]->from)->toBe('studio@pec.test')
        ->and($mailbox->sent[0]->to)->toBe('sdi01@pec.fatturapa.it')
        ->and($mailbox->sent[0]->subject)->toBe('IT01234567897_00001.xml')
        ->and($mailbox->sent[0]->attachmentName)->toBe('IT01234567897_00001.xml')
        ->and($mailbox->sent[0]->attachmentContents)->toContain('<ProgressivoInvio>00001</ProgressivoInvio>');

    $transmissions = app(SdiTransmissions::class)->forInvoice($invoice->id);
    expect($transmissions)->toHaveCount(1)
        ->and($transmissions[0]->messageId)->toBe('fake-1@studio.test')
        ->and($transmissions[0]->fileName)->toBe('IT01234567897_00001.xml')
        ->and($transmissions[0]->transportStatus())->toStartWith('inviata il');

    Event::assertDispatched(InvoiceSent::class, fn (InvoiceSent $e): bool => $e->channel === SendChannel::Pec);
    expect(array_map(fn ($e) => $e->action, app(AuditTrail::class)->forSubject($sent->reference())))->toContain('invoicing.invoice.sent');

    expect(fn () => app(SendToSdi::class)->send($invoice->id))->toThrow(LogicException::class);
});

it('refuses to send when the channel is not set up, and when the message does not leave nothing changes', function (): void {
    $invoice = Studio::issued(Studio::client(), [Studio::line('Sito', '1000.00')]);

    $mailbox = new FakePecMailbox;
    $mailbox->configured = false;
    app()->instance(PecMailbox::class, $mailbox);
    expect(app(SendToSdi::class)->isAvailable())->toBeFalse()
        ->and(fn () => app(SendToSdi::class)->send($invoice->id))->toThrow(ValidationException::class);

    $mailbox = pecChannel();
    $mailbox->failWith = 'SMTP 550';
    expect(fn () => app(SendToSdi::class)->send($invoice->id))->toThrow(RuntimeException::class)
        ->and(SdiTransmission::query()->count())->toBe(0)
        ->and(App\Modules\Invoicing\Models\Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Issued);
});

it('sends every issued invoice through the job only when automatic sending is on', function (): void {
    $client = Studio::client();
    $mailbox = pecChannel(autoSend: false);
    Studio::issued($client, [Studio::line('a', '10.00')]);
    Studio::issued($client, [Studio::line('b', '10.00')]);

    $off = app(SendIssuedInvoices::class)->run();
    expect($off->succeeded())->toBeTrue()->and($off->output)->toContain('spento')->and($mailbox->sent)->toBe([]);

    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, true, true));
    $on = app(SendIssuedInvoices::class)->run();
    expect($on->succeeded())->toBeTrue()->and($mailbox->sent)->toHaveCount(2)->and($on->output)->toContain('1/2026')->toContain('2/2026');

    expect(app(SendIssuedInvoices::class)->run()->output)->toBe('Nessuna fattura da inviare.');

    Studio::issued($client, [Studio::line('c', '10.00')]);
    $mailbox->failWith = 'timeout';
    $failed = app(SendIssuedInvoices::class)->run();
    expect($failed->succeeded())->toBeFalse()->and($failed->output)->toContain('timeout');
});
