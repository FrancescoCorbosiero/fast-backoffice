<?php

declare(strict_types=1);

use App\Kernel\Reference;
use App\Modules\Cash\Contributions\MovementsContactPanel;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Allocations;
use App\Modules\Cash\Filament\Pages\StatementPage;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\EditAccount;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Cash\Filament\Resources\Movements\Pages\CreateMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\EditMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\ListMovements;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Modules\Cash\Filament\Widgets\AccountBalancesWidget;
use App\Modules\Cash\Filament\Widgets\ContactMovementsTable;
use App\Modules\Cash\Filament\Widgets\ExpenseCategoriesWidget;
use App\Modules\Cash\Filament\Widgets\YearToDateWidget;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Contacts\Models\Contact;
use Carbon\CarbonImmutable;
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
    Livewire::test(ContactMovementsTable::class, ['contactId' => $contact->id])->assertSee('Saldo progetto')->assertSee('123,45');
});

it('offers to record a movement from the contact page, both from the section and from its empty state', function (): void {
    $contact = Contact::factory()->create();
    $url = CreateMovement::prefilledUrl(['contact_id' => $contact->id]);

    expect($url)->toEndWith("/admin/cash/movements/create?contact_id={$contact->id}")
        ->and((new MovementsContactPanel)->createAction($contact->id)->getUrl())->toBe($url);

    Livewire::test(ContactMovementsTable::class, ['contactId' => $contact->id])
        ->assertSee('Movimenti di cassa')
        ->assertSee('Nessun movimento')
        ->assertSee('I pagamenti delle sue fatture arrivano qui da soli; tutto il resto lo registri a mano.')
        ->assertSee('Nuovo movimento')
        ->assertSee("contact_id={$contact->id}", escape: false);

    Movement::factory()->for(Account::factory()->create())->create(['contact_id' => $contact->id]);

    Livewire::test(ContactMovementsTable::class, ['contactId' => $contact->id])
        ->assertDontSee('Nessun movimento')
        ->assertSee('Nuovo movimento')
        ->assertSee("contact_id={$contact->id}", escape: false);
});

it('opens the movement form already knowing the counterparty it was reached from', function (): void {
    Account::factory()->create();
    $contact = Contact::factory()->create(['display_name' => 'Fornitore Srl']);

    $this->get("/admin/cash/movements/create?contact_id={$contact->id}")->assertOk()->assertSee('Fornitore Srl');

    Livewire::withQueryParams(['contact_id' => (string) $contact->id])
        ->test(CreateMovement::class)
        ->assertSchemaStateSet(['contact_id' => $contact->id, 'direction' => 'out', 'is_taxable' => true]);

    Livewire::withQueryParams(['contact_id' => 'abc'])
        ->test(CreateMovement::class)
        ->assertSchemaStateSet(['contact_id' => null]);
});

it('labels a logged recurring expense and leads from the movement to its schedule', function (): void {
    $account = Account::factory()->create();
    $expense = RecurringExpense::factory()->create(['name' => 'Hosting Hetzner']);
    Movement::factory()->for($account)->out(5_000, '2026-03-01')->allocatedTo("recurring_expense:{$expense->id}:2026-03-01")->create(['description' => 'Hosting Hetzner (mar 2026)']);
    $url = "/admin/cash/recurring-expenses/{$expense->id}/edit";

    expect(Allocations::label(Reference::fromString("recurring_expense:{$expense->id}:2026-03-01")))->toBe('Spesa ricorrente Hosting Hetzner · mar 2026')
        ->and(Allocations::url(Reference::fromString("recurring_expense:{$expense->id}:2026-03-01")))->toEndWith($url);

    Livewire::test(ListMovements::class)->assertSee('Spesa ricorrente Hosting Hetzner · mar 2026')->assertSee($url, escape: false)->assertSee('Apri documento');
});

it('separates the movements owned by a document from the free ones still to categorise', function (): void {
    $account = Account::factory()->create();
    $payment = Movement::factory()->for($account)->allocatedTo('invoice:1')->create(['category_id' => Category::factory()->income()->create()->id]);
    $free = Movement::factory()->for($account)->create();

    Livewire::test(ListMovements::class)
        ->filterTable('allocated', true)->assertCanSeeTableRecords([$payment])->assertCanNotSeeTableRecords([$free])
        ->resetTableFilters()->filterTable('uncategorised')->assertCanSeeTableRecords([$free])->assertCanNotSeeTableRecords([$payment]);
});

it('leads from an account to its statement, from the list and from its page', function (): void {
    $account = Account::factory()->create(['name' => 'Banca']);
    $url = StatementPage::getUrl(['account' => $account->id]);

    expect($url)->toEndWith("/admin/cash/statement?account={$account->id}");
    Livewire::test(ListAccounts::class)->assertSee('Estratto conto')->assertSee($url, escape: false);
    Livewire::test(EditAccount::class, ['record' => $account->id])->assertActionExists('statement')->assertSee($url, escape: false);
});

it('opens each statement line, shows the balances around the month and offers the missing movement already placed', function (): void {
    $account = Account::factory()->create(['opening_balance_cents' => 10_000, 'opened_on' => '2026-01-01']);
    Movement::factory()->for($account)->in(5_000, '2026-02-10')->create();
    $free = Movement::factory()->for($account)->out(2_000, '2026-03-05')->create(['description' => 'Cancelleria']);
    Movement::factory()->for($account)->out(1_000, '2026-03-20')->allocatedTo('invoice:1')->create(['description' => 'Acconto']);

    Livewire::withQueryParams(['account' => (string) $account->id, 'month' => '2026-03'])
        ->test(StatementPage::class)
        ->assertSee('Saldo iniziale')->assertSee('150,00')
        ->assertSee('Saldo finale')->assertSee('120,00')
        ->assertSee("/admin/cash/movements/{$free->id}/edit", escape: false)
        ->assertSee("account_id={$account->id}", escape: false)->assertSee('occurred_on=2026-03-01', escape: false)
        ->assertSee('month=2026-02', escape: false)->assertSee('month=2026-04', escape: false);

    Livewire::withQueryParams(['account' => (string) $account->id, 'month' => '2026-05'])
        ->test(StatementPage::class)
        ->assertSee('Nessun movimento nel mese')->assertSee('Nuovo movimento');
});

it('opens the movement form already on the account and day it was reached from', function (): void {
    $account = Account::factory()->create();

    Livewire::withQueryParams(['account_id' => (string) $account->id, 'occurred_on' => '2026-03-01', 'direction' => 'in'])
        ->test(CreateMovement::class)
        ->assertSchemaStateSet(['account_id' => $account->id, 'occurred_on' => '2026-03-01', 'direction' => 'in']);

    expect(CreateMovement::urlFor($account->id, CarbonImmutable::parse('2026-03-01'), Direction::In))
        ->toEndWith("/admin/cash/movements/create?account_id={$account->id}&occurred_on=2026-03-01&direction=in");
});

it('records a giroconto as two movements outside the analytics', function (): void {
    $bank = Account::factory()->create(['name' => 'Banca']);
    $paypal = Account::factory()->create(['name' => 'PayPal']);

    Livewire::test(ListMovements::class)
        ->callAction('transfer', ['from_account_id' => $paypal->id, 'to_account_id' => $paypal->id, 'amount' => '500', 'occurred_on' => '2026-03-01'])
        ->assertHasActionErrors(['to_account_id']);

    Livewire::test(ListMovements::class)
        ->callAction('transfer', ['from_account_id' => $paypal->id, 'to_account_id' => $bank->id, 'amount' => '500', 'occurred_on' => '2026-03-01'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $movements = Movement::query()->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements[0]->account_id)->toBe($paypal->id)->and($movements[0]->direction)->toBe(Direction::Out)
        ->and($movements[1]->account_id)->toBe($bank->id)->and($movements[1]->direction)->toBe(Direction::In)
        ->and($movements->pluck('is_taxable')->all())->toBe([false, false])
        ->and($movements[0]->description)->toBe('Giroconto PayPal → Banca')
        ->and($movements[0]->amount_cents)->toBe(50_000);
});

it('flags overdue recurring expenses in the sidebar and isolates them in the list', function (): void {
    $this->travelTo('2026-03-10');
    $late = RecurringExpense::factory()->create(['name' => 'Late', 'next_due_on' => '2026-03-01']);
    $soon = RecurringExpense::factory()->create(['name' => 'Soon', 'next_due_on' => '2026-03-20']);
    $off = RecurringExpense::factory()->create(['name' => 'Off', 'next_due_on' => '2026-03-01', 'is_active' => false]);

    expect(RecurringExpenseResource::getNavigationBadge())->toBe('1')->and(RecurringExpenseResource::getNavigationBadgeColor())->toBe('danger');

    Livewire::test(ListRecurringExpenses::class)
        ->assertCanSeeTableRecords([$late, $soon])->assertCanNotSeeTableRecords([$off])
        ->filterTable('overdue')->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$soon]);
});

it('shows what a schedule has already paid and logs the next payment from its own page', function (): void {
    $account = Account::factory()->create();
    $expense = RecurringExpense::factory()->create(['account_id' => $account->id, 'next_due_on' => '2026-02-20']);
    Movement::factory()->for($account)->out(5_000, '2026-01-20')->allocatedTo("recurring_expense:{$expense->id}:2026-01-20")->create(['description' => 'Figma (gen 2026)']);

    Livewire::test(EditRecurringExpense::class, ['record' => $expense->id])
        ->assertSee('Pagamenti registrati')->assertSee('Figma (gen 2026)')
        ->assertSee(e(StatementPage::getUrl(['account' => $account->id, 'month' => '2026-01'])), escape: false)
        ->callAction('log', ['paid_on' => '2026-02-21', 'account_id' => $account->id])
        ->assertNotified();

    expect(Movement::query()->count())->toBe(2)->and($expense->refresh()->next_due_on->toDateString())->toBe('2026-03-20');
});

it('leads from every dashboard figure to the screen behind it', function (): void {
    $this->travelTo('2026-03-10');
    $account = Account::factory()->create(['name' => 'Banca', 'opening_balance_cents' => 123_400]);
    $category = Category::factory()->create(['name' => 'Software']);
    Movement::factory()->for($account)->out(2_000, '2026-02-01')->create(['category_id' => $category->id]);

    $this->get('/admin')->assertOk();
    Livewire::test(AccountBalancesWidget::class)
        ->assertSee('Banca')->assertSee('1.214,00')->assertSee('Prossimi 3 mesi')
        ->assertSee(StatementPage::getUrl(['account' => $account->id]), escape: false)
        ->assertSee('/admin/cash/projection?months=3', escape: false);
    Livewire::test(YearToDateWidget::class)
        ->assertSee(e(YearToDateWidget::movementsUrl(CarbonImmutable::today(), Direction::Out)), escape: false);
    Livewire::test(ExpenseCategoriesWidget::class)
        ->assertSee('Software')
        ->assertSee(e(YearToDateWidget::movementsUrl(CarbonImmutable::today(), Direction::Out, $category->id)), escape: false);
});
