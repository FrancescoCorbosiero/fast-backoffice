<?php

declare(strict_types=1);

use App\Modules\Invoicing\Actions\ProcessPecMessage;
use App\Modules\Invoicing\Actions\SendToSdi;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Filament\Resources\PecMessages\Pages\ListPecMessages;
use App\Modules\Invoicing\Filament\Resources\PecMessages\Pages\ViewPecMessage;
use App\Modules\Invoicing\Filament\Widgets\InvoicingOverviewWidget;
use App\Modules\Invoicing\Integrations\Pec\IncomingPecMessage;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PecMessage;
use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Data\SdiSettings;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;
use Tests\Support\FakePecMailbox;
use Tests\Support\PecFixtures;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-12 10:00:00');
    $this->mailbox = new FakePecMailbox;
    app()->instance(PecMailbox::class, $this->mailbox);
    app(SaveSdiSettings::class)->save(new SdiSettings('studio@pec.test', SdiSettings::FIRST_SDI_ADDRESS, false, true));
});

it('sends to SdI from the invoice page, then shows the outcome and the resend path', function (): void {
    $invoice = Studio::issued(Studio::client(), [Studio::line('Sito', '1000.00')], '2026-03-10');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertActionVisible('sendSdi')
        ->callAction('sendSdi')
        ->assertNotified()
        ->assertActionHidden('sendSdi');
    expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Sent);
    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('Sistema di Interscambio')->assertSee('inviata il');

    app(ProcessPecMessage::class)->process(new IncomingPecMessage('u1', PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_NS_001.xml', PecFixtures::ns('IT01234567897_00001.xml', [['00404', 'Fattura duplicata']]), 'application/xml']])));

    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('Fattura duplicata')->assertSee('riemetti entro il');
    $this->get("/admin/invoices/{$invoice->id}/edit")->assertOk();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertActionVisible('reissue')->assertActionVisible('void')
        ->callAction('reissue')
        ->assertNotified();
    expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Issued)->and(Invoice::query()->findOrFail($invoice->id)->sdi_file_name)->toBe('IT01234567897_00002.xml');

    Livewire::test(InvoicingOverviewWidget::class)->assertSee('Esiti SdI da gestire');
});

it('voids a rejected invoice from its page once the window is over', function (): void {
    $invoice = Studio::issued(Studio::client(), [Studio::line('Sito', '1000.00')], '2026-03-10');
    app(SendToSdi::class)->send($invoice->id);
    app(ProcessPecMessage::class)->process(new IncomingPecMessage('u1', PecFixtures::envelope('posta-certificata', [['IT01234567897_00001_NS_001.xml', PecFixtures::ns('IT01234567897_00001.xml', [['00404', 'Fattura duplicata']]), 'application/xml']])));
    $this->travelTo('2026-03-20 10:00:00');

    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('finestra di reinvio chiusa');
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('reissue')->callAction('void')->assertNotified();
    expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Void);
});

it('hides the send action when the channel is not configured', function (): void {
    $this->mailbox->configured = false;
    $invoice = Studio::issued(Studio::client(), [Studio::line('Sito', '1000.00')]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('sendSdi')->assertActionVisible('sent');
});

it('lists the PEC messages, reads the mailbox on demand and lets the owner resolve what needs review', function (): void {
    $this->mailbox->receive(PecFixtures::plain('Newsletter'));

    $this->get('/admin/pec-messages')->assertOk();
    Livewire::test(ListPecMessages::class)->callAction('read')->assertNotified();

    $message = PecMessage::query()->sole();
    expect($message->outcome)->toBe(PecMessageOutcome::Review);

    $this->get("/admin/pec-messages/{$message->id}")->assertOk()->assertSee('Newsletter')->assertSee('Da verificare');
    Livewire::test(ViewPecMessage::class, ['record' => $message->id])
        ->callAction('raw')->assertFileDownloaded("message-{$message->id}.eml")
        ->callAction('resolve', ['note' => 'Era pubblicità'])
        ->assertNotified();
    expect($message->refresh()->outcome)->toBe(PecMessageOutcome::Processed)->and($message->note)->toBe('Era pubblicità');
});
