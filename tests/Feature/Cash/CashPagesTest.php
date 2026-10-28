<?php

declare(strict_types=1);

use App\Modules\Cash\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Cash\Filament\Resources\Movements\Pages\CreateMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\EditMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\ListMovements;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    signInAsOwner();
});

it('renders the cash screens', function (string $url): void {
    Account::factory()->create();
    $this->get($url)->assertOk();
})->with(['/admin/cash/movements', '/admin/cash/recurring-expenses', '/admin/cash/accounts', '/admin/cash/categories', '/admin/cash/statement', '/admin/cash/projection', '/admin']);

it('creates an account with an opening balance and lists balances', function (): void {
    Livewire::test(CreateAccount::class)
        ->fillForm(['name' => 'Banca', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '1234,56', 'opened_on' => '2026-01-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = Account::query()->sole();
    Movement::factory()->for($account)->in(100)->create();

    expect($account->opening_balance_cents)->toBe(123_456);
    Livewire::test(ListAccounts::class)->assertSee('1.235,56');
});

it('records a movement from the form through the module action', function (): void {
    $account = Account::factory()->create();
    $category = Category::factory()->create(['name' => 'Software']);
    $contact = Contact::factory()->create(['display_name' => 'Fornitore Srl']);

    Livewire::test(CreateMovement::class)
        ->fillForm(['direction' => 'out', 'amount' => '49,90', 'occurred_on' => '2026-03-01', 'account_id' => $account->id, 'description' => 'Licenza', 'category_id' => $category->id, 'contact_id' => $contact->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $movement = Movement::query()->sole();
    expect($movement->amount_cents)->toBe(4_990)->and($movement->contact_id)->toBe($contact->id);

    Livewire::test(ListMovements::class)->assertSee('Fornitore Srl')->assertSee('Licenza');
});

it('rejects a bad amount or a mismatched category in the form', function (): void {
    $account = Account::factory()->create();
    $incomeCategory = Category::factory()->income()->create();

    Livewire::test(CreateMovement::class)
        ->fillForm(['direction' => 'out', 'amount' => '0', 'occurred_on' => '2026-03-01', 'account_id' => $account->id, 'description' => 'x', 'category_id' => $incomeCategory->id])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(Movement::query()->exists())->toBeFalse();
});

it('lists movements with their counterparties in a constant number of queries', function (): void {
    $account = Account::factory()->create();
    $contacts = Contact::factory()->count(3)->create();
    foreach ($contacts as $contact) {
        Movement::factory()->for($account)->create(['contact_id' => $contact->id]);
    }
    Livewire::test(ListMovements::class)->assertOk();

    DB::enableQueryLog();
    Livewire::test(ListMovements::class)->assertOk();
    $queries = count(DB::getQueryLog());

    foreach (Contact::factory()->count(15)->create() as $contact) {
        Movement::factory()->for($account)->create(['contact_id' => $contact->id]);
    }
    DB::flushQueryLog();
    Livewire::test(ListMovements::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($queries);
});

it('keeps allocated movements read-only on the cash screens', function (): void {
    $account = Account::factory()->create();
    $payment = Movement::factory()->for($account)->allocatedTo('invoice:1')->create();

    $this->get("/admin/cash/movements/{$payment->id}/edit")->assertForbidden();

    $plain = Movement::factory()->for($account)->create();
    Livewire::test(EditMovement::class, ['record' => $plain->id])
        ->fillForm(['description' => 'Changed'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($plain->refresh()->description)->toBe('Changed');

    Livewire::test(EditMovement::class, ['record' => $plain->id])->callAction('delete');
    expect(Movement::query()->whereKey($plain->id)->exists())->toBeFalse();
});

it('logs a recurring expense payment from the list', function (): void {
    $account = Account::factory()->create();
    $expense = RecurringExpense::factory()->create(['account_id' => $account->id, 'next_due_on' => '2026-01-20']);

    Livewire::test(ListRecurringExpenses::class)
        ->callTableAction('log', $expense, ['paid_on' => '2026-01-22', 'account_id' => $account->id])
        ->assertNotified();

    expect(Movement::query()->sole()->occurred_on->toDateString())->toBe('2026-01-22')
        ->and($expense->refresh()->next_due_on->toDateString())->toBe('2026-02-20');
});

it('shows a contact\'s movements on the contact page', function (): void {
    $contact = Contact::factory()->create();
    $account = Account::factory()->create();
    Movement::factory()->for($account)->in(12_345)->create(['contact_id' => $contact->id, 'description' => 'Saldo progetto']);

    $this->get("/admin/contacts/{$contact->id}")->assertOk()->assertSee('Movimenti di cassa');
    Livewire::test(App\Modules\Cash\Filament\Widgets\ContactMovementsTable::class, ['contactId' => $contact->id])->assertSee('Saldo progetto')->assertSee('123,45');
});
