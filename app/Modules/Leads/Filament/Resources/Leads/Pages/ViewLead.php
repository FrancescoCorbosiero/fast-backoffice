<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Leads\Actions\ConvertLead;
use App\Modules\Leads\Data\ConversionInput;
use App\Modules\Leads\Data\ConversionResult;
use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Filament\Actions\MoveLeadAction;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Quotes\Queries\FindQuote;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
        array_push($header, ...$this->outcome($data));

        $components = [Section::make()->columns(3)->components($header)];
        if ($data->notes !== null) {
            $components[] = Section::make('Note')->compact()->components([TextEntry::make('h_notes')->label('')->state($data->notes)]);
        }

        return $schema->components($components);
    }

    /**
     * What the lead became: the contact, the quote and the site by name,
     * each one a link to its page.
     *
     * @return list<TextEntry>
     */
    private function outcome(LeadData $data): array
    {
        $entries = [];

        if ($data->contactId !== null) {
            $contact = app(FindContact::class)->byId($data->contactId);
            $entries[] = TextEntry::make('h_contact')->label('Contatto')->state($contact === null ? 'apri' : $contact->displayName)->url(ContactLink::url($data->contactId));
        }
        if ($data->quoteId !== null) {
            $quote = app(FindQuote::class)->byId($data->quoteId);
            $entries[] = TextEntry::make('h_quote')->label('Preventivo')
                ->state($quote === null ? 'apri' : $quote->title().($quote->subject !== null ? ' · '.$quote->subject : ''))
                ->badge($quote !== null)->color($quote?->statusOn(Date::today())->color())
                ->url(url('/admin/quotes/'.$data->quoteId));
        }
        if ($data->websiteId !== null) {
            $website = app(Websites::class)->find($data->websiteId);
            $entries[] = TextEntry::make('h_site')->label('Sito')->state($website === null ? 'apri' : $website->name)->url(WebsiteLink::url($data->websiteId));
        }

        return $entries;
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
                    LinesSchema::repeater()->visible(fn (Get $get): bool => (bool) $get('with_quote'))->minItems(fn (Get $get): int => $get('with_quote') ? 1 : 0)->defaultItems(1),
                    Toggle::make('with_website')->label('Registra un sito')->live(),
                    TextInput::make('website_name')->label('Nome del sito')->maxLength(120)->visible(fn (Get $get): bool => (bool) $get('with_website'))->required(fn (Get $get): bool => (bool) $get('with_website')),
                    TextInput::make('website_url')->label('Indirizzo del sito')->url()->maxLength(500)->visible(fn (Get $get): bool => (bool) $get('with_website'))->required(fn (Get $get): bool => (bool) $get('with_website')),
                ])
                ->action(function (array $data): void {
                    /** @var array<string, mixed> $data */
                    try {
                        $result = app(ConvertLead::class)->convert($this->lead()->id, ConversionInput::fromArray(LinesSchema::toInput($data)));
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

                        return;
                    } catch (LogicException $exception) {
                        Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $this->converted($result);
                    $this->redirect(ContactLink::url($result->contactId));
                }),
            MoveLeadAction::make()->after(function (): void {
                $this->record = $this->lead()->refresh();
            }),
        ];
    }

    /**
     * The conversion done: what was created, each one a click away. The
     * owner lands on the contact; the quote and the site stay in the toast.
     */
    private function converted(ConversionResult $result): void
    {
        $actions = [Action::make('contact')->label('Apri contatto')->url(ContactLink::url($result->contactId))];
        if ($result->quoteId !== null) {
            $actions[] = Action::make('quote')->label('Apri preventivo')->url(url('/admin/quotes/'.$result->quoteId));
        }
        if ($result->websiteId !== null) {
            $actions[] = Action::make('website')->label('Apri sito')->url(WebsiteLink::url($result->websiteId));
        }

        Notification::make()
            ->title('Lead convertito')
            ->body(implode(', ', array_filter([$result->contactCreated ? 'contatto creato' : 'collegato al contatto', $result->quoteId !== null ? 'preventivo creato' : null, $result->websiteId !== null ? 'sito registrato' : null])))
            ->success()->persistent()
            ->actions($actions)
            ->send();
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
