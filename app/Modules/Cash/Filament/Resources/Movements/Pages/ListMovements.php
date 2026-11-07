<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements\Pages;

use App\Kernel\Money\Money;
use App\Modules\Cash\Actions\TransferBetweenAccounts;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Queries\FindContact;
use App\Support\Filament\FormErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ListMovements extends ListRecords
{
    protected static string $resource = MovementResource::class;

    /** @var array<int, string>|null */
    private ?array $contactNames = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transfer')
                ->label('Giroconto')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->modalDescription('Sposta denaro tra due dei tuoi conti: due movimenti, fuori dal primo e dentro il secondo, che restano nei saldi ma non nei ricavi e nei costi.')
                ->fillForm(['occurred_on' => CarbonImmutable::today()->toDateString()])
                ->schema([
                    Select::make('from_account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                    Select::make('to_account_id')->label('Al conto')->options(fn (): array => app(Accounts::class)->options())->required()->different('from_account_id'),
                    Amounts::input('amount'),
                    DatePicker::make('occurred_on')->label('Data')->required(),
                    TextInput::make('description')->label('Descrizione')->maxLength(200)->placeholder('Giroconto'),
                ])
                ->action(function (array $data, Schema $schema): void {
                    try {
                        [$out] = app(TransferBetweenAccounts::class)->transfer(
                            is_numeric($data['from_account_id'] ?? null) ? (int) $data['from_account_id'] : 0,
                            is_numeric($data['to_account_id'] ?? null) ? (int) $data['to_account_id'] : 0,
                            Money::fromDecimal(is_scalar($data['amount'] ?? null) ? (string) $data['amount'] : '0'),
                            CarbonImmutable::parse(is_string($data['occurred_on'] ?? null) ? $data['occurred_on'] : 'today'),
                            is_string($data['description'] ?? null) ? $data['description'] : null,
                        );
                    } catch (ValidationException $exception) {
                        FormErrors::onSchema($schema, $exception);
                    }

                    Notification::make()->title('Giroconto registrato')->body($out->description.': '.Amounts::format($out->amount))->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * The counterparties of the page's rows, looked up once through the
     * Contacts module rather than once per row.
     */
    public function contactName(?int $contactId): ?string
    {
        if ($contactId === null) {
            return null;
        }

        if ($this->contactNames === null) {
            $records = $this->getTableRecords();
            $items = $records instanceof Collection ? $records->all() : $records->items();
            $ids = [];

            foreach ($items as $record) {
                if ($record instanceof Movement && $record->contact_id !== null) {
                    $ids[$record->contact_id] = $record->contact_id;
                }
            }

            $this->contactNames = array_map(fn ($contact): string => $contact->displayName, app(FindContact::class)->byIds(array_values($ids)));
        }

        return $this->contactNames[$contactId] ?? null;
    }
}
