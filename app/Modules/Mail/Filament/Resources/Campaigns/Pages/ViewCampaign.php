<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns\Pages;

use App\Modules\Contacts\Queries\SearchContacts;
use App\Modules\Mail\Actions\PauseCampaign;
use App\Modules\Mail\Actions\SaveCampaign;
use App\Modules\Mail\Actions\StartCampaign;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Widgets\CampaignRecipientsTable;
use App\Modules\Mail\Models\Campaign;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
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
            $header[] = TextEntry::make('c_preview')->label('Riceveranno')->state(count(app(SearchContacts::class)->mailable($data->audienceRoles)).' contatti');
        } else {
            $header[] = TextEntry::make('c_progress')->label('Avanzamento')->state(sprintf('%d in coda · %d inviate · %d consegnate · %d bounce · %d spam · %d fallite · %d saltate',
                $data->count('queued'), $data->count('sent'), $data->count('delivered'), $data->count('bounced', 'soft_bounced'), $data->count('complained'), $data->count('failed'), $data->count('skipped')))->columnSpanFull();
        }

        $components = [
            Section::make()->columns(4)->components($header),
            Section::make('Contenuto')->compact()->components([TextEntry::make('c_body')->label('')->state(new HtmlString($campaign->body_html))]),
        ];
        if (! $data->status->isEditable()) {
            $components[] = Section::make('Destinatari')->components([Livewire::make(CampaignRecipientsTable::class, ['campaignId' => $campaign->id])]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => $this->campaign()->status->isEditable()),
            Action::make('send')->label('Invia ora')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
                ->visible(fn (): bool => $this->campaign()->status->isEditable())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'La campagna parte verso '.count(app(SearchContacts::class)->mailable($this->campaign()->roleEnums())).' contatti, al ritmo impostato. Non si torna indietro.')
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
                ->visible(fn (): bool => in_array($this->campaign()->status, [CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true))
                ->requiresConfirmation()
                ->action(fn () => $this->run(function (): string {
                    app(PauseCampaign::class)->cancel($this->campaign()->id);

                    return 'Campagna annullata';
                })),
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
