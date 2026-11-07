<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites\Pages;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Queries\RecurringInvoices;
use App\Modules\Websites\Actions\CheckWebsite;
use App\Modules\Websites\Actions\LogHostingRenewal;
use App\Modules\Websites\Actions\SetWebsiteStatus;
use App\Modules\Websites\Actions\StartSubscription;
use App\Modules\Websites\Actions\StopSubscription;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Models\Website;
use App\Support\Shape;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewWebsite extends ViewRecord
{
    protected static string $resource = WebsiteResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Website ? $record->name : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->website()->toData();
        $today = Date::today();

        $header = [
            TextEntry::make('h_contact')->label('Cliente')->state(app(FindContact::class)->byId($data->contactId)->displayName ?? '—')->url(ContactLink::url($data->contactId)),
            TextEntry::make('h_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('h_url')->label('Indirizzo')->state($data->url)->url($data->url, true),
            TextEntry::make('h_up')->label('Online')->state(match (true) {
                ! $data->isMonitored() => 'non controllato',
                $data->isUp === null => 'mai controllato',
                $data->downSince !== null => 'GIÙ dal '.$data->downSince->isoFormat('D MMM HH:mm'),
                $data->isUp => 'sì ('.($data->lastStatusCode ?? '—').', '.($data->lastResponseMs ?? '—').' ms)',
                default => 'ultimo controllo fallito',
            })->color($data->downSince !== null ? 'danger' : 'success'),
            TextEntry::make('h_checked')->label('Ultimo controllo')->state($data->lastCheckedAt?->isoFormat('D MMM YYYY HH:mm') ?? '—'),
        ];

        $hosting = [];
        if ($data->hostingProvider !== null || $data->hasHostingRenewal()) {
            $hosting[] = TextEntry::make('o_provider')->label('Fornitore')->state($data->hostingProvider ?? '—');
        }
        if ($data->hasHostingRenewal()) {
            $hosting[] = TextEntry::make('o_renews')->label('Prossimo rinnovo')->state($data->hostingRenewsOn?->isoFormat('D MMM YYYY') ?? '—')->color($data->hostingRenewalOverdue($today) ? 'danger' : null);
            $hosting[] = TextEntry::make('o_cost')->label('Costo')->state($data->hostingCost !== null ? $data->hostingCost->format().' · '.($data->hostingFrequency?->label() ?? '') : '—');
        } else {
            $hosting[] = TextEntry::make('o_none')->hiddenLabel()->columnSpan(2)->state('Hosting non tracciato: imposta costo e cadenza in Modifica per seguire i rinnovi.');
        }
        foreach (app(AllocatedMovements::class)->matching('hosting_renewal', $data->id.':') as $movement) {
            $hosting[] = TextEntry::make('o_m'.$movement->id)->label('Rinnovo pagato')->state($movement->occurredOn->isoFormat('D MMM YYYY').' · '.$movement->amount->format())->url(url('/admin/cash/movements/'.$movement->id.'/edit'));
        }

        $subscription = [];
        if ($data->subscriptionId !== null) {
            $schedule = app(RecurringInvoices::class)->find($data->subscriptionId);
            $subscription[] = TextEntry::make('s_name')->label('Canone')->state($schedule !== null ? $schedule->name : '—')->url($schedule === null ? null : url('/admin/recurring-invoices/'.$schedule->id.'/edit'));
            $subscription[] = TextEntry::make('s_next')->label('Prossima fattura')->state($schedule?->nextIssueOn->isoFormat('D MMM YYYY') ?? '—');
            $subscription[] = TextEntry::make('s_freq')->label('Cadenza')->state($schedule?->frequency->label() ?? '—');
            if ($schedule !== null) {
                $subscription[] = TextEntry::make('s_amount')->label('Imponibile')->state(self::amountOf($schedule->lines)->format());
                $subscription[] = TextEntry::make('s_issue')->label('Emissione')->state(($schedule->autoIssue ? 'automatica' : 'manuale').' · '.$schedule->paymentTermsDays.' gg');
            }
        } elseif ($data->status === WebsiteStatus::Active) {
            $subscription[] = TextEntry::make('s_none')->hiddenLabel()->columnSpanFull()->state('Nessun canone attivo: avvialo da qui per fatturare il sito a cadenza.');
        } else {
            $subscription[] = TextEntry::make('s_none')->hiddenLabel()->columnSpanFull()->state('Il sito è '.mb_strtolower($data->status->label()).': riattivalo per avviare un canone.');
        }

        $components = [
            Section::make()->columns(5)->components($header),
            Section::make('Hosting')->compact()->columns(3)->components($hosting)->headerActions([
                Action::make('hosting')->label('Imposta hosting')->icon(Heroicon::OutlinedPencilSquare)->link()
                    ->visible(! $data->hasHostingRenewal())
                    ->url(WebsiteResource::getUrl('edit', ['record' => $data->id])),
            ]),
            Section::make('Canone')->key('canone')->compact()->columns(3)->components($subscription)->headerActions([
                $this->subscribeAction('subscribe_from_section')->link(),
            ]),
        ];
        if ($data->notes !== null) {
            $components[] = Section::make('Note')->compact()->components([TextEntry::make('h_notes')->label('')->state($data->notes)]);
        }

        foreach ($this->panels() as $panel) {
            $components[] = $panel->component($this->website()->id)->key('panel-'.$panel->key());
        }

        return $schema->components($components);
    }

    /**
     * The sections modules that link to websites contribute (domains,
     * repositories…), in their order.
     *
     * @return list<WebsitePanel>
     */
    private function panels(): array
    {
        /** @var iterable<WebsitePanel> $panels */
        $panels = app()->tagged(WebsitePanel::TAG);
        $sorted = [];

        foreach ($panels as $panel) {
            $sorted[] = $panel;
        }

        usort($sorted, fn (WebsitePanel $a, WebsitePanel $b): int => $a->sort() <=> $b->sort());

        return $sorted;
    }

    /**
     * The "Nuovo" menu: one entry per section that creates things.
     *
     * @return list<Action>
     */
    private function createActions(): array
    {
        $actions = [];

        foreach ($this->panels() as $panel) {
            $action = $panel->createAction($this->website()->id);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    protected function getHeaderActions(): array
    {
        $create = $this->createActions();

        return [
            ActionGroup::make($create)
                ->label('Nuovo')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->button()
                ->visible($create !== []),
            EditAction::make(),
            Action::make('check')->label('Controlla ora')->icon(Heroicon::OutlinedSignal)
                ->visible(fn (): bool => $this->website()->toData()->isMonitored())
                ->action(function (): void {
                    $result = app(CheckWebsite::class)->check($this->website()->id);
                    $this->refreshPage();

                    $result->isUp
                        ? Notification::make()->title('Online')->body('HTTP '.$result->statusCode.' in '.$result->responseMs.' ms')->success()->send()
                        : Notification::make()->title('Non risponde: '.($result->error ?? 'nessuna risposta'))->danger()->persistent()->send();
                }),
            Action::make('renewal')->label('Registra rinnovo hosting')->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (): bool => $this->website()->toData()->hasHostingRenewal() && $this->website()->status === WebsiteStatus::Active)
                ->fillForm(fn (): array => ['paid_on' => today()->toDateString(), 'account_id' => $this->website()->hosting_account_id])
                ->schema([
                    DatePicker::make('paid_on')->label('Pagato il')->required(),
                    Select::make('account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $accountId = $data['account_id'] ?? null;
                        app(LogHostingRenewal::class)->log($this->website()->id, self::date($data, 'paid_on'), is_numeric($accountId) ? (int) $accountId : null);

                        return 'Rinnovo registrato';
                    });
                }),
            $this->subscribeAction('subscribe'),
            Action::make('unsubscribe')->label('Ferma canone')->icon(Heroicon::OutlinedStopCircle)->color('gray')
                ->visible(fn (): bool => $this->website()->subscription_id !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->run(function (): string {
                        app(StopSubscription::class)->stop($this->website()->id);

                        return 'Canone fermato';
                    });
                }),
            Action::make('status')->label('Cambia stato')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->fillForm(fn (): array => ['status' => $this->website()->status->value])
                ->schema([Select::make('status')->label('Stato')->options(WebsiteStatus::options())->required()])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $status = WebsiteStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '') ?? WebsiteStatus::Active;
                        app(SetWebsiteStatus::class)->set($this->website()->id, $status);

                        return 'Stato: '.$status->label();
                    });
                }),
        ];
    }

    /**
     * "Avvia canone", in the page header and in the empty Canone section.
     * Built fresh on every call with its own name: an Action instance
     * belongs to one place.
     */
    private function subscribeAction(string $name): Action
    {
        $frequencies = [];
        foreach (Frequency::cases() as $frequency) {
            $frequencies[$frequency->value] = $frequency->label();
        }

        return Action::make($name)->label('Avvia canone')->icon(Heroicon::OutlinedArrowPathRoundedSquare)->color('primary')
            ->visible(fn (): bool => $this->website()->subscription_id === null && $this->website()->status === WebsiteStatus::Active)
            ->schema([
                Select::make('frequency')->label('Cadenza')->options($frequencies)->default(Frequency::Monthly->value)->required(),
                DatePicker::make('started_on')->label('Prima fattura')->default(today())->required(),
                TextInput::make('payment_terms_days')->label('Termini (giorni)')->integer()->minValue(0)->maxValue(365)->default(30),
                Toggle::make('auto_issue')->label('Emetti automaticamente'),
                LinesSchema::repeater(),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): string {
                    /** @var array<string, mixed> $data */
                    $input = LinesSchema::toInput($data);
                    $lines = array_map(fn (array $line): LineInput => LineInput::fromArray($line), Shape::listOfArrays($input, 'lines'));
                    $terms = $data['payment_terms_days'] ?? null;
                    app(StartSubscription::class)->start(
                        $this->website()->id,
                        $lines,
                        Frequency::tryFrom(is_string($data['frequency'] ?? null) ? $data['frequency'] : '') ?? Frequency::Monthly,
                        self::date($data, 'started_on') ?? CarbonImmutable::today(),
                        (bool) ($data['auto_issue'] ?? false),
                        is_numeric($terms) ? (int) $terms : null,
                    );

                    return 'Canone avviato';
                });
            });
    }

    /**
     * What the client is billed per period, before VAT: the natura and the
     * VAT of each issue are the regime's business, applied by Invoicing
     * when the invoice is issued.
     *
     * @param  list<LineInput>  $lines
     */
    private static function amountOf(array $lines): Money
    {
        $currency = $lines === [] ? 'EUR' : $lines[0]->unitPrice->currency;

        return Money::sum(array_map(fn (LineInput $line): Money => $line->net(), $lines), $currency);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @param  callable(): string  $callback
     */
    private function run(callable $callback): void
    {
        try {
            $message = $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

            return;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->refreshPage();
        Notification::make()->title($message)->success()->send();
    }

    /**
     * After an action: the record re-read and the content built again,
     * since the schema was cached before a section action ran.
     */
    private function refreshPage(): void
    {
        $this->record = $this->website()->refresh();
        unset($this->cachedSchemas['content']);
    }

    private function website(): Website
    {
        $record = $this->getRecord();

        if (! $record instanceof Website) {
            throw new LogicException('The page shows websites.');
        }

        return $record;
    }
}
