<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains\Pages;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Domains\Actions\LogDomainRenewal;
use App\Modules\Domains\Actions\SetDomainStatus;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Domain ? $record->name : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->domain()->toData();
        $today = Date::today();
        $website = $data->websiteId === null ? null : app(Websites::class)->find($data->websiteId);
        $editUrl = DomainResource::getUrl('edit', ['record' => $data->id]);

        // Whose domain it is comes first: when it expires, the next step is to call or bill them.
        $header = [
            TextEntry::make('h_contact')->label('Cliente')->state(app(FindContact::class)->byId($data->contactId)->displayName ?? '—')->url(ContactLink::url($data->contactId)),
            TextEntry::make('h_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('h_expires')->label('Scade il')->state($data->expiresOn->isoFormat('D MMM YYYY').($data->isExpired($today) ? ' · scaduto' : ''))->color($data->isExpired($today) ? 'danger' : ($data->expiresWithin($today, 30) ? 'warning' : null)),
            TextEntry::make('h_registrar')->label('Registrar')->state($data->registrar ?? '—'),
            $data->renewalCost === null
                ? TextEntry::make('h_renewal')->label('Rinnovo')->state('ogni '.$data->renewalMonths.' mesi · costo da impostare')->color('warning')->url($editUrl)
                : TextEntry::make('h_renewal')->label('Rinnovo')->state($data->renewalCost->format().' ogni '.$data->renewalMonths.' mesi'.($data->autoRenew ? ' · automatico' : '')),
            $website === null
                ? TextEntry::make('h_site')->label('Sito')->state('nessuno · collega')->color('gray')->url($editUrl)
                : TextEntry::make('h_site')->label('Sito')->state($website->name)->url(WebsiteLink::url($website->id)),
        ];

        $renewals = [];
        foreach (app(AllocatedMovements::class)->matching('domain_renewal', $data->id.':') as $movement) {
            $renewals[] = TextEntry::make('r'.$movement->id)->label('')
                ->state($movement->occurredOn->isoFormat('D MMM YYYY').' · '.$movement->amount->format().' · '.$movement->description)
                ->url(self::movementUrl($movement->id));
        }
        if ($renewals === []) {
            $renewals[] = TextEntry::make('r_none')->label('')->state('Nessun rinnovo registrato.');
        }

        $components = [
            Section::make()->columns(count($header))->components($header),
            Section::make('Rinnovi pagati')->compact()->components($renewals),
        ];
        if ($data->notes !== null) {
            $components[] = Section::make('Note')->compact()->components([TextEntry::make('h_notes')->label('')->state($data->notes)]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('renewal')->label('Registra rinnovo')->icon(Heroicon::OutlinedBanknotes)->color('primary')
                ->visible(fn (): bool => $this->domain()->status === DomainStatus::Active)
                ->disabled(fn (): bool => ! $this->domain()->toData()->hasPaidRenewal())
                ->tooltip(fn (): ?string => $this->domain()->toData()->hasPaidRenewal() ? null : 'Imposta un costo di rinnovo per registrare i pagamenti')
                ->fillForm(fn (): array => ['paid_on' => today()->toDateString(), 'account_id' => $this->domain()->account_id])
                ->modalDescription(fn (): string => 'Registra il pagamento del periodo che scade il '.$this->domain()->expires_on->isoFormat('D MMM YYYY').' e sposta la scadenza di '.$this->domain()->renewal_months.' mesi.')
                ->schema([
                    DatePicker::make('paid_on')->label('Pagato il')->required(),
                    Select::make('account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): Notification {
                        $accountId = $data['account_id'] ?? null;
                        $paidOn = $data['paid_on'] ?? null;
                        $movement = app(LogDomainRenewal::class)->log($this->domain()->id, is_string($paidOn) && $paidOn !== '' ? CarbonImmutable::parse($paidOn) : null, is_numeric($accountId) ? (int) $accountId : null);

                        return Notification::make()->title('Rinnovo registrato')->success()
                            ->actions([Action::make('open')->label('Apri movimento')->url(self::movementUrl($movement->id))]);
                    });
                }),
            Action::make('status')->label('Cambia stato')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->fillForm(fn (): array => ['status' => $this->domain()->status->value])
                ->schema([Select::make('status')->label('Stato')->options(DomainStatus::options())->required()])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): Notification {
                        $status = DomainStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '') ?? DomainStatus::Active;
                        app(SetDomainStatus::class)->set($this->domain()->id, $status);

                        return Notification::make()->title('Stato: '.$status->label())->success();
                    });
                }),
        ];
    }

    /**
     * The Cash movement a renewal was paid with, to fix a wrong account,
     * date or amount from here. Cash is a lower layer and has no link
     * component, so the page knows its URL, as it does for websites.
     */
    private static function movementUrl(int $movementId): string
    {
        return url('/admin/cash/movements/'.$movementId.'/edit');
    }

    /**
     * @param  callable(): Notification  $callback  what to say when it worked
     */
    private function run(callable $callback): void
    {
        try {
            $notification = $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

            return;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->record = $this->domain()->refresh();
        $notification->send();
    }

    private function domain(): Domain
    {
        $record = $this->getRecord();

        if (! $record instanceof Domain) {
            throw new LogicException('The page shows domains.');
        }

        return $record;
    }
}
