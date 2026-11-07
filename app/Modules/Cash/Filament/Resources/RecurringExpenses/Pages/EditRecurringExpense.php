<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages;

use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Components\LogRecurringExpenseAction;
use App\Modules\Cash\Filament\Pages\StatementPage;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\AllocatedMovements;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use LogicException;

class EditRecurringExpense extends EditRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LogRecurringExpenseAction::make(fn (): RecurringExpense => $this->expense())
                ->visible(fn (): bool => $this->expense()->is_active)
                ->after(fn () => $this->redirect(RecurringExpenseResource::getUrl('edit', ['record' => $this->expense()->id]))),
        ];
    }

    /**
     * The form, then what has already been paid: each occurrence logged,
     * leading to the statement it sits in (a logged payment belongs to its
     * schedule and is read-only on the movement screens).
     */
    public function content(Schema $schema): Schema
    {
        $payments = [];

        foreach (array_reverse(app(AllocatedMovements::class)->matching('recurring_expense', $this->expense()->id.':')) as $movement) {
            $payments[] = self::paymentEntry($movement);
        }

        if ($payments === []) {
            $payments[] = TextEntry::make('p_none')->label('')->state('Nessun pagamento registrato.');
        }

        return $schema->components([
            $this->getFormContentComponent(),
            Section::make('Pagamenti registrati')->compact()->components($payments),
        ]);
    }

    private static function paymentEntry(MovementData $movement): TextEntry
    {
        return TextEntry::make('p'.$movement->id)->label('')
            ->state($movement->occurredOn->isoFormat('D MMM YYYY').' · '.Amounts::format($movement->amount).' · '.$movement->description)
            ->url(StatementPage::getUrl(['account' => $movement->accountId, 'month' => $movement->occurredOn->format('Y-m')]));
    }

    private function expense(): RecurringExpense
    {
        $record = $this->getRecord();

        if (! $record instanceof RecurringExpense) {
            throw new LogicException('The form edits recurring expenses.');
        }

        return $record;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Amounts::decimal($data['amount_cents'] ?? 0);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['amount_cents'] = Amounts::cents($data['amount'] ?? '0');
        unset($data['amount']);

        // A schedule that hasn't been logged yet follows its new first date.
        $record = $this->getRecord();
        if ($record->getAttribute('next_due_on') === $record->getAttribute('started_on') && isset($data['started_on'])) {
            $data['next_due_on'] = $data['started_on'];
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringExpenseResource::getUrl('index');
    }
}
