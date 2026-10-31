<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Actions\RebuildPaidAmounts;
use App\Modules\Invoicing\Actions\RecordPayment;
use App\Modules\Invoicing\Actions\RemovePayment;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Events\InvoicePaid;
use App\Modules\Invoicing\Events\PaymentRecorded;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Queries\FindInvoice;
use App\Modules\Invoicing\Queries\InvoicePayments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

function payment(string $amount, int $accountId, string $on = '2026-03-20'): PaymentInput
{
    return PaymentInput::fromArray(['amount' => $amount, 'paid_on' => $on, 'account_id' => $accountId]);
}

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15');
});

it('records a payment as one cash movement and derives the payment state with precedence', function (): void {
    Event::fake([PaymentRecorded::class, InvoicePaid::class]);
    $client = Studio::client();
    $account = Studio::account();
    $invoice = Studio::issued($client, [Studio::line('Sito', '1000.00')]);
    $today = CarbonImmutable::today();

    expect($invoice->paymentState($today))->toBe(PaymentState::Unpaid);

    $partial = app(RecordPayment::class)->record($invoice->id, payment('400', $account->id));
    expect($partial->paid->cents)->toBe(40_000)
        ->and($partial->outstanding()->cents)->toBe(60_000)
        ->and($partial->paymentState($today))->toBe(PaymentState::PartiallyPaid);
    Event::assertDispatchedTimes(PaymentRecorded::class, 1);
    Event::assertNotDispatched(InvoicePaid::class);

    $movement = Movement::query()->sole();
    expect($movement->allocation)->toBe("invoice:{$invoice->id}")
        ->and($movement->direction->value)->toBe('in')
        ->and($movement->contact_id)->toBe($client->id)
        ->and($movement->description)->toContain('1/2026');

    $paid = app(RecordPayment::class)->record($invoice->id, payment('600', $account->id));
    expect($paid->paymentState($today))->toBe(PaymentState::Paid)->and($paid->outstanding()->isZero())->toBeTrue();
    Event::assertDispatchedTimes(InvoicePaid::class, 1);

    expect(app(InvoicePayments::class)->forInvoice($invoice->id))->toHaveCount(2)
        ->and(fn () => app(RecordPayment::class)->record($invoice->id, payment('1', $account->id)))->toThrow(ValidationException::class);
});

it('flags overdue from the day after the due date, even when partly paid', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $invoice = Studio::issued($client, [Studio::line('Sito', '1000.00')], '2026-03-01', 10);
    app(RecordPayment::class)->record($invoice->id, payment('300', $account->id));
    $data = app(FindInvoice::class)->byId($invoice->id);

    expect($data?->dueOn?->toDateString())->toBe('2026-03-11')
        ->and($data?->paymentState(CarbonImmutable::parse('2026-03-11')))->toBe(PaymentState::PartiallyPaid)
        ->and($data?->paymentState(CarbonImmutable::parse('2026-03-12')))->toBe(PaymentState::Overdue);

    app(RecordPayment::class)->record($invoice->id, payment('700', $account->id));
    expect(app(FindInvoice::class)->byId($invoice->id)?->paymentState(CarbonImmutable::parse('2026-04-01')))->toBe(PaymentState::Paid);
});

it('removes a payment: the movement goes and the state walks back, nothing is left in the ledger', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $invoice = Studio::issued($client, [Studio::line('Sito', '100.00')]);
    $paid = app(RecordPayment::class)->record($invoice->id, payment('100', $account->id));
    $movementId = app(InvoicePayments::class)->forInvoice($invoice->id)[0]->movementId;

    expect($paid->paymentState(CarbonImmutable::today()))->toBe(PaymentState::Paid);

    $after = app(RemovePayment::class)->remove($invoice->id, $movementId);

    expect($after->paid->isZero())->toBeTrue()
        ->and($after->paymentState(CarbonImmutable::today()))->toBe(PaymentState::Unpaid)
        ->and(Movement::query()->count())->toBe(0)
        ->and(app(AllocatedMovements::class)->total($invoice->reference())->isZero())->toBeTrue();
});

it('refuses payments on drafts, credit notes and from the cash screens', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $draft = Studio::draft($client, [Studio::line('x', '10.00')]);

    expect(fn () => app(RecordPayment::class)->record($draft->id, payment('10', $account->id)))->toThrow(ValidationException::class);

    $invoice = Studio::issued($client, [Studio::line('x', '10.00')]);
    app(RecordPayment::class)->record($invoice->id, payment('10', $account->id));
    $movement = Movement::query()->sole();

    expect(fn () => app(App\Modules\Cash\Actions\DeleteMovement::class)->delete($movement->id))->toThrow(LogicException::class);
});

it('rebuilds cached paid amounts from the movements and reports what drifted', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $invoice = Studio::issued($client, [Studio::line('x', '100.00')]);
    app(RecordPayment::class)->record($invoice->id, payment('40', $account->id));
    DB::table('invoices')->where('id', $invoice->id)->update(['paid_cents' => 999]);

    expect(app(RebuildPaidAmounts::class)->rebuild())->toBe(1)
        ->and(Invoice::query()->findOrFail($invoice->id)->paid_cents)->toBe(4_000)
        ->and(app(RebuildPaidAmounts::class)->rebuild())->toBe(0);

    $this->artisan('invoicing:rebuild-paid')->expectsOutputToContain('matched')->assertSuccessful();
});
