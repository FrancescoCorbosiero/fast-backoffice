<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Components;

use App\Modules\Cash\Actions\LogRecurringExpense;
use App\Modules\Cash\Filament\Pages\StatementPage;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\Accounts;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * "Registra pagamento" for a recurring expense, wherever the schedule
 * shows up: its list, its own page, the projection's overdue rows. The
 * caller says how to find the schedule from the row and when the action
 * is visible; the success toast leads to the statement the payment landed in.
 */
final class LogRecurringExpenseAction
{
    /**
     * @param  Closure(mixed): ?RecurringExpense  $expense  the schedule behind the row the action sits on
     */
    public static function make(Closure $expense): Action
    {
        return Action::make('log')
            ->label('Registra pagamento')
            ->icon(Heroicon::OutlinedCheck)
            ->fillForm(function (mixed $record = null) use ($expense): array {
                $schedule = $expense($record);

                return $schedule === null ? [] : ['paid_on' => $schedule->next_due_on->toDateString(), 'account_id' => $schedule->account_id];
            })
            ->schema([
                DatePicker::make('paid_on')->label('Pagata il')->required(),
                Select::make('account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
            ])
            ->action(function (array $data, mixed $record = null) use ($expense): void {
                $schedule = $expense($record);

                if ($schedule === null) {
                    Notification::make()->title('Spesa ricorrente non trovata')->danger()->send();

                    return;
                }

                try {
                    $movement = app(LogRecurringExpense::class)->log(
                        $schedule->id,
                        is_string($data['paid_on'] ?? null) ? CarbonImmutable::parse($data['paid_on']) : null,
                        is_numeric($data['account_id'] ?? null) ? (int) $data['account_id'] : null,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title('Pagamento non registrato')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Pagamento registrato')
                    ->body($movement->description.': '.Amounts::format($movement->amount))
                    ->success()
                    ->actions([
                        Action::make('statement')->label('Apri estratto conto')
                            ->url(StatementPage::getUrl(['account' => $movement->accountId, 'month' => $movement->occurredOn->format('Y-m')])),
                    ])
                    ->send();
            });
    }
}
