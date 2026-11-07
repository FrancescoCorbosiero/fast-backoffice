<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Resources\Bookings\Pages;

use App\Modules\Calendar\Actions\LinkBookingToContact;
use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Models\Booking;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Leads\Filament\Components\LeadLink;
use App\Support\Filament\FormErrors;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->booking()->title;
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->booking()->toData();

        $entries = [
            TextEntry::make('b_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('b_when')->label('Quando')->state($data->startsAt->isoFormat('dddd D MMMM YYYY, HH:mm').' – '.$data->endsAt->isoFormat('HH:mm')),
            TextEntry::make('b_tz')->label('Fuso del cliente')->state($data->timezone ?? '—'),
            TextEntry::make('b_type')->label('Tipo')->state($data->eventType ?? '—'),
            TextEntry::make('b_who')->label('Con')->state(($data->attendeeName ?? '—').($data->attendeeEmail !== null ? ' · '.$data->attendeeEmail : '')),
            TextEntry::make('b_where')->label('Dove')->state($data->location ?? '—'),
            TextEntry::make('b_uid')->label('Cal.com')->state($data->uid.' · '.$data->source.' · '.$data->syncedAt->isoFormat('D MMM HH:mm'))
                ->url(BookingResource::calComBookingUrl($data->uid), shouldOpenInNewTab: true),
        ];
        if ($data->contactId !== null) {
            $contact = app(FindContact::class)->byId($data->contactId);
            $entries[] = TextEntry::make('b_contact')->label('Contatto')->state($contact === null ? 'apri' : $contact->displayName)->url(ContactLink::url($data->contactId));
        } else {
            $entries[] = TextEntry::make('b_contact')->label('Contatto')->state('Nessun contatto con questa email')->color('warning')
                ->helperText('Crea un lead o un contatto dai dati della prenotazione, oppure collegala a un contatto esistente.');
        }
        if ($data->notes !== null) {
            $entries[] = TextEntry::make('b_notes')->label('Note')->state($data->notes)->columnSpanFull();
        }
        if ($data->cancellationReason !== null) {
            $entries[] = TextEntry::make('b_cancel')->label('Annullato perché')->state($data->cancellationReason)->columnSpanFull();
        }

        return $schema->components([Section::make()->columns(2)->components($entries)]);
    }

    protected function getHeaderActions(): array
    {
        $data = $this->booking()->toData();
        $unlinked = $data->contactId === null;

        return [
            Action::make('calcom')->label('Apri in Cal.com')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(BookingResource::calComBookingUrl($data->uid))->openUrlInNewTab(),
            Action::make('lead')->label('Crea lead')->icon(Heroicon::OutlinedFunnel)->visible($unlinked)
                ->url(LeadLink::createUrl(['name' => $data->attendeeName, 'email' => $data->attendeeEmail, 'source' => 'cal.com'])),
            Action::make('contact')->label('Crea contatto')->icon(Heroicon::OutlinedUserPlus)->visible($unlinked)
                ->url(ContactLink::createUrl()),
            Action::make('link')->label($unlinked ? 'Collega a un contatto' : 'Cambia contatto')->icon(Heroicon::OutlinedLink)->color($unlinked ? 'primary' : 'gray')
                ->modalDescription('Da ora la prenotazione compare sulla pagina del contatto; le prossime sincronizzazioni non toccano il collegamento.')
                ->schema([ContactPicker::make('contact_id', [ContactRole::Client])->required()])
                ->action(function (array $data, Schema $schema): void {
                    $contactId = $data['contact_id'] ?? null;
                    try {
                        app(LinkBookingToContact::class)->link($this->booking()->id, is_numeric($contactId) ? (int) $contactId : 0);
                    } catch (ValidationException $exception) {
                        FormErrors::onSchema($schema, $exception);
                    }

                    $this->record = $this->booking()->refresh();
                    Notification::make()->title('Prenotazione collegata al contatto')->success()->send();
                }),
        ];
    }

    private function booking(): Booking
    {
        $record = $this->getRecord();

        if (! $record instanceof Booking) {
            throw new LogicException('The page shows bookings.');
        }

        return $record;
    }
}
