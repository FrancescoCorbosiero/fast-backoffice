<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Data\BookingData;
use App\Modules\Calendar\Data\BookingInput;
use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Events\BookingCancelled;
use App\Modules\Calendar\Events\BookingRecorded;
use App\Modules\Calendar\Models\Booking;
use App\Modules\Contacts\Queries\FindContact;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * One booking per Cal.com uid, whatever the channel: created or updated,
 * linked to the contact with the attendee's email when there is one.
 */
final readonly class RecordBooking
{
    public function __construct(
        private ConnectionInterface $db,
        private FindContact $contacts,
        private Dispatcher $events,
    ) {}

    public function record(BookingInput $input, string $source): BookingData
    {
        return $this->db->transaction(function () use ($input, $source): BookingData {
            $booking = Booking::query()->lockForUpdate()->where('external_uid', $input->uid)->first();
            $previousStatus = $booking?->status;
            $contactId = $booking?->contact_id;

            if ($contactId === null && $input->attendeeEmail !== null) {
                $contactId = $this->contacts->byEmail($input->attendeeEmail)?->id;
            }

            $attributes = [
                'title' => mb_substr($input->title, 0, 200),
                'event_type' => $input->eventType === null ? null : mb_substr($input->eventType, 0, 120),
                'status' => $input->status,
                'starts_at' => $input->startsAt,
                'ends_at' => $input->endsAt,
                'timezone' => $input->timezone,
                'attendee_name' => $input->attendeeName === null ? null : mb_substr($input->attendeeName, 0, 160),
                'attendee_email' => $input->attendeeEmail,
                'location' => $input->location === null ? null : mb_substr($input->location, 0, 500),
                'contact_id' => $contactId,
                'notes' => $input->notes,
                'cancellation_reason' => $input->cancellationReason === null ? null : mb_substr($input->cancellationReason, 0, 500),
                'source' => $source,
                'payload' => $input->payload,
                'synced_at' => Date::now(),
            ];

            if ($booking === null) {
                $booking = Booking::query()->create([...$attributes, 'external_uid' => $input->uid]);
            } else {
                $booking->forceFill($attributes)->save();
            }

            $data = $booking->toData();
            if ($input->status === BookingStatus::Cancelled && $previousStatus !== BookingStatus::Cancelled) {
                $this->events->dispatch(new BookingCancelled($booking->id, $contactId));
            } elseif ($previousStatus === null) {
                $this->events->dispatch(new BookingRecorded($booking->id, $contactId));
            }

            return $data;
        });
    }
}
