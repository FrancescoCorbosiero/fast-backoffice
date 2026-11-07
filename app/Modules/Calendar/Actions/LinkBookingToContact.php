<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Data\BookingData;
use App\Modules\Calendar\Models\Booking;
use App\Modules\Contacts\Queries\FindContact;
use Illuminate\Validation\ValidationException;

/**
 * A booking whose attendee's email matched no contact (a prospect's
 * discovery call, most of the time) is linked by hand once the owner
 * knows who it is. Later syncs keep the link: RecordBooking never
 * overwrites a contact_id that is already set.
 */
final readonly class LinkBookingToContact
{
    public function __construct(
        private FindContact $contacts,
    ) {}

    public function link(int $bookingId, int $contactId): BookingData
    {
        $booking = Booking::query()->findOrFail($bookingId);

        if ($this->contacts->byId($contactId) === null) {
            throw ValidationException::withMessages(['contact_id' => 'Il contatto scelto non esiste.']);
        }

        $booking->forceFill(['contact_id' => $contactId])->save();

        return $booking->toData();
    }
}
