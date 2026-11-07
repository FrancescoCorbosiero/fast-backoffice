<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns\Pages;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Queries\SearchContacts;
use App\Modules\Mail\Actions\PauseCampaign;
use App\Modules\Mail\Actions\SaveCampaign;
use App\Modules\Mail\Actions\SendTestEmail;
use App\Modules\Mail\Actions\StartCampaign;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Widgets\CampaignRecipientsTable;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Settings\Queries\GetMailSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Campaign ? $record->name : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $campaign = $this->campaign();
        $data = $campaign->toData();

        $header = [
            TextEntry::make('c_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('c_subject')->label('Oggetto')->state($data->subject),
            TextEntry::make('c_audience')->label('Destinatari')->state($data->audienceRoles === [] ? 'tutti i contatti con consenso' : implode(', ', array_map(fn ($r): string => $r->label(), $data->audienceRoles))),
            TextEntry::make('c_when')->label('Quando')->state(match (true) {
                $data->completedAt !== null => 'completata il '.$data->completedAt->isoFormat('D MMM YYYY HH:mm'),
                $data->startedAt !== null => 'avviata il '.$data->startedAt->isoFormat('D MMM YYYY HH:mm'),
                $data->scheduledAt !== null => 'programmata per il '.$data->scheduledAt->isoFormat('D MMM YYYY HH:mm'),
                default => 'non ancora inviata',
            }),
        ];

        if ($data->status->isEditable()) {
            $header[] = TextEntry::make('c_preview')->label('Riceveranno')->state(count($this->audience()).' contatti')->hint('vedi "Chi riceve"');
        } else {
            $header = [...$header, ...$this->progressBadges($campaign)];
        }

        $components = [];
        if ($data->status->isEditable() && ! app(GetMailSettings::class)->get()->isConfigured()) {
            $components[] = Section::make('Mittente non impostato')
                ->description('Senza un indirizzo mittente la campagna non parte: impostalo in Impostazioni › Campagne email.')
                ->icon(Heroicon::OutlinedExclamationTriangle)->iconColor('warning')->compact()
                ->afterHeader([$this->settingsAction()->button()]);
        }
        $components[] = Section::make()->columns(4)->components($header);
        $components[] = Section::make('Contenuto')->compact()->components([TextEntry::make('c_body')->label('')->state(new HtmlString($campaign->body_html))]);
        if (! $data->status->isEditable()) {
            $status = request()->query('status');
            $components[] = Section::make('Destinatari')->components([Livewire::make(CampaignRecipientsTable::class, [
                'campaignId' => $campaign->id,
                'status' => is_string($status) && RecipientStatus::tryFrom($status) !== null ? $status : null,
            ])]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('audience')->label('Chi riceve')->icon(Heroicon::OutlinedUsers)->color('gray')
                ->visible(fn (): bool => $this->campaign()->status->isEditable())
                ->modalHeading('Chi riceve questa campagna')
                ->modalContent(fn (): HtmlString => $this->audienceList())
                ->modalSubmitAction(false)->modalCancelActionLabel('Chiudi'),
            EditAction::make()->visible(fn (): bool => $this->campaign()->status->isEditable()),
            Action::make('test')->label('Invia una prova')->icon(Heroicon::OutlinedEnvelopeOpen)->color('gray')
                ->visible(fn (): bool => $this->campaign()->status->isEditable())
                ->modalDescription('L\'email arriva così com\'è, con l\'oggetto preceduto da [PROVA]. Non conta come destinatario.')
                ->schema([TextInput::make('to')->label('A')->email()->required()->default(fn (): ?string => $this->testAddress())])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $to = $data['to'] ?? '';
                        app(SendTestEmail::class)->send($this->campaign()->id, is_string($to) ? $to : '');

                        return 'Prova inviata a '.(is_string($to) ? mb_strtolower(trim($to)) : '');
                    });
                }),
            Action::make('send')->label('Invia ora')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
                ->visible(fn (): bool => $this->campaign()->status->isEditable())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'La campagna parte verso '.count($this->audience()).' contatti, al ritmo impostato. Non si torna indietro.')
                ->action(function (): void {
                    $this->run(function (): string {
                        $started = app(StartCampaign::class)->start($this->campaign()->id);

                        return 'Campagna in coda per '.$started->total().' destinatari';
                    });
                }),
            Action::make('schedule')->label('Programma')->icon(Heroicon::OutlinedClock)
                ->visible(fn (): bool => $this->campaign()->status->isEditable())
                ->schema([DateTimePicker::make('scheduled_at')->label('Quando')->required()->seconds(false)->minDate(now())])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $at = $data['scheduled_at'] ?? null;
                        app(StartCampaign::class)->start($this->campaign()->id, is_string($at) ? CarbonImmutable::parse($at) : null);

                        return 'Campagna programmata';
                    });
                }),
            Action::make('unschedule')->label('Togli dalla programmazione')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn (): bool => $this->campaign()->status === CampaignStatus::Scheduled)
                ->requiresConfirmation()
                ->modalDescription('La campagna torna bozza: la puoi modificare, riprogrammare o eliminare. Nessuna email è partita.')
                ->action(fn () => $this->run(function (): string {
                    app(PauseCampaign::class)->unschedule($this->campaign()->id);

                    return 'Campagna tornata in bozza';
                })),
            Action::make('pause')->label('Pausa')->icon(Heroicon::OutlinedPause)->color('warning')
                ->visible(fn (): bool => $this->campaign()->status === CampaignStatus::Sending)
                ->action(fn () => $this->run(function (): string {
                    app(PauseCampaign::class)->pause($this->campaign()->id);

                    return 'Campagna in pausa';
                })),
            Action::make('resume')->label('Riprendi')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn (): bool => $this->campaign()->status === CampaignStatus::Paused)
                ->action(fn () => $this->run(function (): string {
                    app(PauseCampaign::class)->resume($this->campaign()->id);

                    return 'Campagna ripresa';
                })),
            Action::make('cancel')->label('Annulla')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (): bool => in_array($this->campaign()->status, [CampaignStatus::Sending, CampaignStatus::Paused], true))
                ->requiresConfirmation()
                ->modalDescription('Chi è ancora in coda non riceve nulla. La campagna non si riprende più.')
                ->action(fn () => $this->run(function (): string {
                    app(PauseCampaign::class)->cancel($this->campaign()->id);

                    return 'Campagna annullata';
                })),
            Action::make('duplicate')->label('Duplica')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->action(function (): void {
                    $copy = app(SaveCampaign::class)->duplicate($this->campaign()->id);
                    Notification::make()->title('Bozza creata da "'.$this->campaign()->name.'"')->success()->send();
                    $this->redirect(CampaignResource::getUrl('edit', ['record' => $copy->id]));
                }),
            Action::make('delete')->label('Elimina bozza')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->visible(fn (): bool => $this->campaign()->status === CampaignStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(SaveCampaign::class)->delete($this->campaign()->id);
                    Notification::make()->title('Bozza eliminata')->success()->send();
                    $this->redirect(CampaignResource::getUrl('index'));
                }),
        ];
    }

    /**
     * One badge per recipient state with a count, each opening the table
     * below filtered on that state; zero counts stay out of the way.
     *
     * @return list<TextEntry>
     */
    private function progressBadges(Campaign $campaign): array
    {
        $counts = $campaign->toData()->counts;
        $badges = [];
        foreach (RecipientStatus::cases() as $status) {
            $n = $counts[$status->value] ?? 0;
            if ($n === 0) {
                continue;
            }
            $badges[] = TextEntry::make('c_count_'.$status->value)->label($status->label())
                ->state((string) $n)->badge()->color($status->color())
                ->url(CampaignResource::getUrl('view', ['record' => $campaign->id, 'status' => $status->value]));
        }

        return $badges === [] ? [TextEntry::make('c_progress')->label('Avanzamento')->state('nessun destinatario')] : $badges;
    }

    /**
     * @return list<ContactData>
     */
    private function audience(): array
    {
        return app(SearchContacts::class)->mailable($this->campaign()->roleEnums());
    }

    private function audienceList(): HtmlString
    {
        $audience = $this->audience();
        if ($audience === []) {
            return new HtmlString('<p class="text-sm text-gray-500">Nessun contatto con email e consenso ha uno dei ruoli scelti.</p>');
        }

        $items = array_map(fn (ContactData $contact): string => sprintf(
            '<li class="py-1.5"><a href="%s" class="font-medium text-primary-600 hover:underline">%s</a> <span class="text-sm text-gray-500">%s</span></li>',
            e(ContactLink::url($contact->id)),
            e($contact->displayName),
            e((string) $contact->email),
        ), $audience);

        return new HtmlString('<p class="mb-2 text-sm text-gray-500">'.count($audience).' contatti con email e consenso. Chi ha revocato il consenso o ha un indirizzo bloccato non compare.</p><ul class="divide-y divide-gray-100 dark:divide-white/10">'.implode('', $items).'</ul>');
    }

    /**
     * Where a proof goes by default: the signed-in owner, else the
     * reply-to or sender from the settings.
     */
    private function testAddress(): ?string
    {
        $user = auth()->user();
        $email = $user instanceof Model ? $user->getAttribute('email') : null;
        if (is_string($email) && $email !== '') {
            return $email;
        }
        $settings = app(GetMailSettings::class)->get();

        return $settings->replyTo ?? $settings->fromAddress;
    }

    private function settingsAction(): Action
    {
        return Action::make('settings')->label('Apri le impostazioni')->icon(Heroicon::OutlinedCog6Tooth)->url(route('filament.admin.pages.settings.mail'));
    }

    /**
     * @param  callable(): string  $callback
     */
    private function run(callable $callback): void
    {
        try {
            $message = $callback();
        } catch (ValidationException $exception) {
            $notification = Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent();
            if ($exception->validator->errors()->has('from_address')) {
                $notification->actions([$this->settingsAction()->button()]);
            }
            $notification->send();

            return;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->record = $this->campaign()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    private function campaign(): Campaign
    {
        $record = $this->getRecord();

        if (! $record instanceof Campaign) {
            throw new LogicException('The page shows campaigns.');
        }

        return $record;
    }
}
