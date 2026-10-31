<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Resources\Bookings\Pages;

use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Models\Booking;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use LogicException;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Booking ? $record->title : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $record = $this->getRecord();
        if (! $record instanceof Booking) {
            throw new LogicException('The page shows bookings.');
        }
        $data = $record->toData();

        $entries = [
            TextEntry::make('b_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('b_when')->label('Quando')->state($data->startsAt->isoFormat('dddd D MMMM YYYY, HH:mm').' – '.$data->endsAt->isoFormat('HH:mm')),
            TextEntry::make('b_tz')->label('Fuso del cliente')->state($data->timezone ?? '—'),
            TextEntry::make('b_type')->label('Tipo')->state($data->eventType ?? '—'),
            TextEntry::make('b_who')->label('Con')->state(($data->attendeeName ?? '—').($data->attendeeEmail !== null ? ' · '.$data->attendeeEmail : '')),
            TextEntry::make('b_where')->label('Dove')->state($data->location ?? '—'),
            TextEntry::make('b_uid')->label('Cal.com')->state($data->uid.' · '.$data->source.' · '.$data->syncedAt->isoFormat('D MMM HH:mm')),
        ];
        if ($data->contactId !== null) {
            $entries[] = TextEntry::make('b_contact')->label('Contatto')->state('apri')->url(url('/admin/contacts/'.$data->contactId));
        }
        if ($data->notes !== null) {
            $entries[] = TextEntry::make('b_notes')->label('Note')->state($data->notes)->columnSpanFull();
        }
        if ($data->cancellationReason !== null) {
            $entries[] = TextEntry::make('b_cancel')->label('Annullato perché')->state($data->cancellationReason)->columnSpanFull();
        }

        return $schema->components([Section::make()->columns(2)->components($entries)]);
    }
}
