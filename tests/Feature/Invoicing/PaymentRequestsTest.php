<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Invoicing\Actions\ConvertPaymentRequest;
use App\Modules\Invoicing\Actions\CreatePaymentRequest;
use App\Modules\Invoicing\Actions\PayPaymentRequest;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Queries\PaymentRequests;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    $this->travelTo('2026-03-15');
});

it('asks for payment with its own numbering and PDF, nothing fiscal', function (): void {
    $client = Studio::client();

    $request = app(CreatePaymentRequest::class)->create(DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('Acconto sito', '500.00')]]), CarbonImmutable::parse('2026-03-01'));

    expect($request->numberText)->toBe('RP 1/2026')
        ->and($request->status)->toBe(PaymentRequestStatus::Open)
        ->and($request->dueOn?->toDateString())->toBe('2026-03-31')
        ->and($request->total->cents)->toBe(50_000)
        ->and($request->totals->stampDutyCharged->isZero())->toBeTrue()
        ->and(app(FilesOf::class)->get($request->reference(), 'pdf'))->toHaveCount(1)
        ->and(app(PaymentRequests::class)->open())->toHaveCount(1);
});

it('once paid, becomes an invoice dated on the payment and already paid', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $request = app(CreatePaymentRequest::class)->create(DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('Acconto sito', '500.00')]]));

    expect(fn () => app(ConvertPaymentRequest::class)->convert($request->id))->toThrow(ValidationException::class);

    $paid = app(PayPaymentRequest::class)->pay($request->id, PaymentInput::fromArray(['amount' => '500', 'paid_on' => '2026-03-12', 'account_id' => $account->id]));
    expect($paid->status)->toBe(PaymentRequestStatus::Paid)->and($paid->paidOn?->toDateString())->toBe('2026-03-12');

    $invoice = app(ConvertPaymentRequest::class)->convert($request->id);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->numberText)->toBe('1/2026')
        ->and($invoice->issuedOn?->toDateString())->toBe('2026-03-12')
        ->and($invoice->paid->cents)->toBe(50_000)
        ->and($invoice->paymentState(CarbonImmutable::today()))->toBe(PaymentState::Paid)
        ->and(Movement::query()->sole()->allocation)->toBe("invoice:{$invoice->id}")
        ->and(app(PaymentRequests::class)->find($request->id)?->status)->toBe(PaymentRequestStatus::Converted)
        ->and(app(PaymentRequests::class)->find($request->id)?->invoiceId)->toBe($invoice->id);
});

it('refuses an overpayment or a payment on a closed request', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $request = app(CreatePaymentRequest::class)->create(DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('x', '100.00')]]));

    expect(fn () => app(PayPaymentRequest::class)->pay($request->id, PaymentInput::fromArray(['amount' => '101', 'paid_on' => '2026-03-12', 'account_id' => $account->id])))->toThrow(ValidationException::class);

    app(PayPaymentRequest::class)->pay($request->id, PaymentInput::fromArray(['amount' => '100', 'paid_on' => '2026-03-12', 'account_id' => $account->id]));
    expect(fn () => app(PayPaymentRequest::class)->pay($request->id, PaymentInput::fromArray(['amount' => '1', 'paid_on' => '2026-03-12', 'account_id' => $account->id])))->toThrow(ValidationException::class);
});
