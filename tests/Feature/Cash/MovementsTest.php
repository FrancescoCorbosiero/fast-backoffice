<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Cash\Actions\DeleteMovement;
use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Actions\UpdateMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Events\MovementRecorded;
use App\Modules\Cash\Events\MovementRemoved;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Contacts\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function movementInput(Account $account, array $overrides = []): MovementInput
{
    return MovementInput::fromArray([
        'account_id' => $account->id,
        'direction' => 'in',
        'amount' => '100,50',
        'occurred_on' => '2026-03-15',
        'description' => 'Acconto progetto',
        ...$overrides,
    ]);
}

it('records a movement as the only record of money moving', function (): void {
    Event::fake([MovementRecorded::class]);
    $account = Account::factory()->create();
    $category = Category::factory()->income()->create();
    $contact = Contact::factory()->create();

    $movement = app(RecordMovement::class)->record(movementInput($account, ['category_id' => $category->id, 'contact_id' => $contact->id]));

    expect($movement->amount->cents)->toBe(10_050)
        ->and($movement->direction)->toBe(Direction::In)
        ->and($movement->signedAmount()->cents)->toBe(10_050)
        ->and($movement->occurredOn->toDateString())->toBe('2026-03-15')
        ->and($movement->categoryId)->toBe($category->id)
        ->and($movement->contactId)->toBe($contact->id)
        ->and($movement->isAllocated())->toBeFalse()
        ->and(app(Accounts::class)->find($account->id)?->balance->cents)->toBe(10_050);

    Event::assertDispatched(MovementRecorded::class, fn (MovementRecorded $event): bool => $event->movementId === $movement->id && $event->allocation === null);
});

it('validates a movement: positive amount, active account, matching category, after the account opened', function (array $overrides, string $field): void {
    $account = Account::factory()->create(['opened_on' => '2026-01-01']);
    $expenseCategory = Category::factory()->create();
    $closed = Account::factory()->create(['is_active' => false]);

    try {
        app(RecordMovement::class)->record(movementInput($account, array_map(
            fn ($value) => $value === '{closed}' ? $closed->id : ($value === '{expense_category}' ? $expenseCategory->id : $value),
            $overrides,
        )));
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail('No ValidationException was thrown.');
})->with([
    'zero amount' => [['amount' => '0'], 'amount'],
    'no description' => [['description' => ' '], 'description'],
    'closed account' => [['account_id' => '{closed}'], 'account_id'],
    'unknown account' => [['account_id' => 999_999], 'account_id'],
    'expense category on income' => [['category_id' => '{expense_category}'], 'category_id'],
    'before the account opened' => [['occurred_on' => '2025-12-31'], 'occurred_on'],
]);

it('updates and deletes an unallocated movement, recording both', function (): void {
    Event::fake([MovementRemoved::class]);
    $account = Account::factory()->create();
    $movement = app(RecordMovement::class)->record(movementInput($account));

    $updated = app(UpdateMovement::class)->update($movement->id, movementInput($account, ['amount' => '80', 'direction' => 'out']));

    expect($updated->signedAmount()->cents)->toBe(-8_000)
        ->and(app(AuditTrail::class)->latest(1)[0]->details)->toMatchArray(['amount_before' => '100.50', 'amount_after' => '-80.00']);

    app(DeleteMovement::class)->delete($movement->id);
    app(DeleteMovement::class)->delete($movement->id);

    expect(Movement::query()->count())->toBe(0)
        ->and(app(AuditTrail::class)->latest(1)[0]->action)->toBe('cash.movement.deleted');
    Event::assertDispatchedTimes(MovementRemoved::class, 1);
});

it('lets only the allocating module change or remove an allocated movement', function (): void {
    $account = Account::factory()->create();
    $invoice = Reference::to('invoice', 7);
    $payment = app(RecordMovement::class)->record(movementInput($account)->withAllocation($invoice));

    expect($payment->allocation?->toString())->toBe('invoice:7')
        ->and(fn () => app(UpdateMovement::class)->update($payment->id, movementInput($account)))->toThrow(LogicException::class)
        ->and(fn () => app(DeleteMovement::class)->delete($payment->id))->toThrow(LogicException::class)
        ->and(fn () => app(DeleteMovement::class)->delete($payment->id, Reference::to('invoice', 8)))->toThrow(LogicException::class);

    $changed = app(UpdateMovement::class)->update($payment->id, movementInput($account, ['amount' => '50']), $invoice);
    expect($changed->allocation?->toString())->toBe('invoice:7')->and($changed->amount->cents)->toBe(5_000);

    app(DeleteMovement::class)->delete($payment->id, $invoice);
    expect(Movement::query()->count())->toBe(0);

    // An unallocated movement can't be claimed by passing an allocation.
    $plain = app(RecordMovement::class)->record(movementInput($account));
    expect(fn () => app(DeleteMovement::class)->delete($plain->id, $invoice))->toThrow(LogicException::class);
});

it('answers the allocating module about its documents in one query', function (): void {
    $account = Account::factory()->create();
    Movement::factory()->for($account)->in(10_000)->allocatedTo('invoice:1')->create();
    Movement::factory()->for($account)->in(2_500)->allocatedTo('invoice:1')->create();
    Movement::factory()->for($account)->out(500)->allocatedTo('invoice:1')->create();
    Movement::factory()->for($account)->in(9_900)->allocatedTo('invoice:2')->create();
    Movement::factory()->for($account)->in(1)->create();
    $allocated = app(AllocatedMovements::class);

    Illuminate\Support\Facades\DB::enableQueryLog();
    $totals = $allocated->totals([Reference::to('invoice', 1), Reference::to('invoice', 2), Reference::to('invoice', 3)]);

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(1)
        ->and(array_map(fn (Money $money): int => $money->cents, $totals))->toBe(['invoice:1' => 12_000, 'invoice:2' => 9_900, 'invoice:3' => 0])
        ->and($allocated->total(Reference::to('invoice', 1))->cents)->toBe(12_000)
        ->and($allocated->for(Reference::to('invoice', 1)))->toHaveCount(3)
        ->and($allocated->exists(Reference::to('invoice', 2)))->toBeTrue()
        ->and($allocated->exists(Reference::to('invoice', 3)))->toBeFalse()
        ->and($allocated->totals([]))->toBe([]);
});

it('computes account balances from the opening balance and the movements, as of a date', function (): void {
    $bank = Account::factory()->create(['opening_balance_cents' => 100_000, 'sort' => 1]);
    $cash = Account::factory()->create(['opening_balance_cents' => 0, 'sort' => 2]);
    Movement::factory()->for($bank)->in(50_000, '2026-02-01')->create();
    Movement::factory()->for($bank)->out(20_000, '2026-03-01')->create();
    Movement::factory()->for($cash)->out(1_000, '2026-03-01')->create();

    $accounts = app(Accounts::class);

    expect(array_map(fn ($account): int => $account->balance->cents, $accounts->all()))->toBe([130_000, -1_000])
        ->and($accounts->find($bank->id, CarbonImmutable::parse('2026-02-15'))?->balance->cents)->toBe(150_000)
        ->and($accounts->find(999))->toBeNull();
});

it('follows a merged contact', function (): void {
    $keep = Contact::factory()->create();
    $duplicate = Contact::factory()->create();
    $account = Account::factory()->create();
    $movement = Movement::factory()->for($account)->create(['contact_id' => $duplicate->id]);
    $expense = RecurringExpense::factory()->create(['contact_id' => $duplicate->id]);

    app(MergeContacts::class)->merge($keep->id, $duplicate->id);

    expect($movement->refresh()->contact_id)->toBe($keep->id)
        ->and($expense->refresh()->contact_id)->toBe($keep->id);
});
