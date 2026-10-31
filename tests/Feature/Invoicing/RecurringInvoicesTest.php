<?php

declare(strict_types=1);

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Queries\CashFlowProjection;
use App\Modules\Invoicing\Actions\IssueRecurringInvoice;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Jobs\IssueDueRecurringInvoices;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Carbon\CarbonImmutable;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
});

it('creates a draft for the cycle and steps to the next date without drifting', function (): void {
    $client = Studio::client();
    $schedule = RecurringInvoice::factory()->create(['contact_id' => $client->id, 'next_issue_on' => '2026-01-31']);

    $draft = app(IssueRecurringInvoice::class)->issue($schedule->id);

    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->notes)->toContain('gennaio 2026')
        ->and($draft->lines[0]->description)->toBe('Canone mensile')
        ->and($schedule->refresh()->next_issue_on->toDateString())->toBe('2026-02-28')
        ->and($schedule->last_issued_on?->toDateString())->toBe('2026-01-31');

    app(IssueRecurringInvoice::class)->issue($schedule->id);
    expect($schedule->refresh()->next_issue_on->toDateString())->toBe('2026-03-31');
});

it('issues at once, dated on the cycle, when the schedule opts in', function (string $frequency, string $from, string $next): void {
    $client = Studio::client();
    $this->travelTo('2027-01-01');
    $schedule = RecurringInvoice::factory()->create(['contact_id' => $client->id, 'frequency' => $frequency, 'next_issue_on' => $from, 'auto_issue' => true]);

    $invoice = app(IssueRecurringInvoice::class)->issue($schedule->id);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->issuedOn?->toDateString())->toBe($from)
        ->and($invoice->numberText)->toBe('1/'.substr($from, 0, 4))
        ->and($schedule->refresh()->next_issue_on->toDateString())->toBe($next);
})->with([
    'monthly' => [Frequency::Monthly->value, '2026-01-31', '2026-02-28'],
    'quarterly' => [Frequency::Quarterly->value, '2026-11-30', '2027-02-28'],
    'yearly' => [Frequency::Yearly->value, '2024-02-29', '2025-02-28'],
]);

it('issues every active schedule that is due through the daily job, one cycle each', function (): void {
    $client = Studio::client();
    $this->travelTo('2026-04-10');
    RecurringInvoice::factory()->count(2)->create(['contact_id' => $client->id, 'next_issue_on' => '2026-04-01']);
    RecurringInvoice::factory()->create(['contact_id' => $client->id, 'next_issue_on' => '2026-02-01']);
    RecurringInvoice::factory()->create(['contact_id' => $client->id, 'next_issue_on' => '2026-04-01', 'is_active' => false]);
    RecurringInvoice::factory()->create(['contact_id' => $client->id, 'next_issue_on' => '2026-05-01']);

    $result = app(IssueDueRecurringInvoices::class)->run();

    expect($result->succeeded())->toBeTrue()
        ->and(Invoice::query()->count())->toBe(3)
        ->and(app(IssueDueRecurringInvoices::class)->run()->output)->toContain('Canone sito')
        ->and(Invoice::query()->count())->toBe(4);

    expect(fn () => app(IssueRecurringInvoice::class)->issue(RecurringInvoice::query()->where('is_active', false)->sole()->id))->toThrow(LogicException::class);
});

it('contributes receivables and recurring invoices to the cash-flow projection', function (): void {
    $client = Studio::client();
    $this->travelTo('2026-05-15');
    Studio::issued($client, [Studio::line('Sito', '1000.00')], '2026-04-01', 30);
    $paidInvoice = Studio::issued($client, [Studio::line('Sito', '100.00')], '2026-05-01', 10);
    app(App\Modules\Invoicing\Actions\RecordPayment::class)->record($paidInvoice->id, App\Modules\Invoicing\Data\PaymentInput::fromArray(['amount' => '100', 'paid_on' => '2026-05-05', 'account_id' => Studio::account()->id]));
    RecurringInvoice::factory()->create(['contact_id' => $client->id, 'next_issue_on' => '2026-06-10', 'payment_terms_days' => 5]);

    $projection = app(CashFlowProjection::class)->project(CarbonImmutable::today(), 3);
    $flows = array_map(fn ($flow): array => [$flow->date->toDateString(), $flow->source, $flow->amount->cents], $projection->flows);

    expect($flows)->toBe([
        ['2026-05-01', 'receivable', 100_000],
        ['2026-06-15', 'recurring_invoice', 10_000],
        ['2026-07-15', 'recurring_invoice', 10_000],
    ])
        ->and($projection->isOverdue($projection->flows[0]))->toBeTrue();
});
