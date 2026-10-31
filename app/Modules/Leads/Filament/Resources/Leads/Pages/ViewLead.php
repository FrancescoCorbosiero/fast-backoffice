<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Leads\Actions\ConvertLead;
use App\Modules\Leads\Actions\MoveLead;
use App\Modules\Leads\Data\ConversionInput;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Lead ? $record->toData()->title() : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->lead()->toData();
        $today = Date::today();

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('h_email')->label('Email')->state($data->email ?? '—'),
            TextEntry::make('h_phone')->label('Telefono')->state($data->phone ?? '—'),
            TextEntry::make('h_source')->label('Fonte')->state($data->source ?? '—'),
            TextEntry::make('h_value')->label('Valore stimato')->state($data->estimatedValue?->format() ?? '—'),
            TextEntry::make('h_follow')->label('Prossimo contatto')->state($data->nextFollowUpOn?->isoFormat('D MMM YYYY') ?? '—')->color($data->isFollowUpDue($today) ? 'danger' : null),
        ];
        if ($data->lostReason !== null) {
            $header[] = TextEntry::make('h_lost')->label('Perso perché')->state($data->lostReason)->columnSpanFull();
        }
        if ($data->contactId !== null) {
            $header[] = TextEntry::make('h_contact')->label('Contatto')->state('apri')->url(url('/admin/contacts/'.$data->contactId));
        }
        if ($data->quoteId !== null) {
            $header[] = TextEntry::make('h_quote')->label('Preventivo')->state('apri')->url(url('/admin/quotes/'.$data->quoteId));
        }
        if ($data->websiteId !== null) {
            $header[] = TextEntry::make('h_site')->label('Sito')->state('apri')->url(url('/admin/websites/'.$data->websiteId));
        }

        $components = [Section::make()->columns(3)->components($header)];
        if ($data->notes !== null) {
            $components[] = Section::make('Note')->compact()->components([TextEntry::make('h_notes')->label('')->state($data->notes)]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => $this->lead()->status !== LeadStatus::Won),
            Action::make('convert')->label('Converti in cliente')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (): bool => $this->lead()->status->isOpen())
                ->modalDescription('In una sola operazione: il contatto (nuovo, o uno esistente), e se vuoi un preventivo e un sito.')
                ->fillForm(fn (): array => ['as_company' => $this->lead()->company !== null, 'website_name' => $this->lead()->company ?? $this->lead()->name, 'with_quote' => false, 'with_website' => false])
                ->schema([
                    ContactPicker::make('existing_contact_id', [ContactRole::Client])->label('Contatto esistente (vuoto: ne creo uno dal lead)'),
                    Toggle::make('as_company')->label('Crea come azienda'),
                    Toggle::make('with_quote')->label('Crea un preventivo')->live(),
                    TextInput::make('quote_subject')->label('Oggetto del preventivo')->maxLength(200)->visible(fn (Get $get): bool => (bool) $get('with_quote')),
                    DatePicker::make('quote_valid_until')->label('Valido fino al')->visible(fn (Get $get): bool => (bool) $get('with_quote')),
                    LinesSchema::repeater()->visible(fn (Get $get): bool => (bool) $get('with_quote'))->minItems(0)->defaultItems(1),
                    Toggle::make('with_website')->label('Registra un sito')->live(),
                    TextInput::make('website_name')->label('Nome del sito')->maxLength(120)->visible(fn (Get $get): bool => (bool) $get('with_website')),
                    TextInput::make('website_url')->label('Indirizzo del sito')->url()->maxLength(500)->visible(fn (Get $get): bool => (bool) $get('with_website')),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        /** @var array<string, mixed> $data */
                        $result = app(ConvertLead::class)->convert($this->lead()->id, ConversionInput::fromArray(LinesSchema::toInput($data)));

                        return 'Lead convertito'.($result->contactCreated ? ': contatto creato' : '').($result->quoteId !== null ? ', preventivo creato' : '').($result->websiteId !== null ? ', sito registrato' : '');
                    });
                }),
            Action::make('move')->label('Sposta')->icon(Heroicon::OutlinedArrowRight)
                ->visible(fn (): bool => $this->lead()->status !== LeadStatus::Won)
                ->fillForm(fn (): array => ['status' => $this->lead()->status->value, 'next_follow_up_on' => $this->lead()->next_follow_up_on?->toDateString()])
                ->schema([
                    Select::make('status')->label('Stato')->options(array_diff_key(LeadStatus::options(), [LeadStatus::Won->value => '']))->required()->live(),
                    DatePicker::make('next_follow_up_on')->label('Prossimo contatto')->visible(fn (Get $get): bool => $get('status') !== LeadStatus::Lost->value),
                    Textarea::make('lost_reason')->label('Perché')->rows(2)->maxLength(500)->visible(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $status = LeadStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '') ?? LeadStatus::New;
                        $reason = $data['lost_reason'] ?? null;
                        $follow = $data['next_follow_up_on'] ?? null;
                        app(MoveLead::class)->move($this->lead()->id, $status, is_string($reason) ? $reason : null, is_string($follow) && $follow !== '' ? CarbonImmutable::parse($follow) : null);

                        return 'Stato: '.$status->label();
                    });
                }),
        ];
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

        $this->record = $this->lead()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    private function lead(): Lead
    {
        $record = $this->getRecord();

        if (! $record instanceof Lead) {
            throw new LogicException('The page shows leads.');
        }

        return $record;
    }
}
