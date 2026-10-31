<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Integrations;

use App\Modules\Calendar\Data\BookingInput;
use App\Modules\Calendar\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a booking out of what Cal.com sends: a webhook body
 * ({triggerEvent, payload: {uid, title, startTime, endTime, attendees…}})
 * or an API v2 booking ({uid, title, start, end, attendees…}). Field
 * names differ between the two and between versions; both are accepted.
 * **verify** against a live account before relying on the mapping.
 */
final class CalComPayload
{
    /**
     * @param  array<array-key, mixed>  $body
     *
     * @throws InvalidArgumentException when there is no booking in it
     */
    public static function fromWebhook(array $body): BookingInput
    {
        $trigger = self::string($body, 'triggerEvent');
        $payload = $body['payload'] ?? null;
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Il webhook non contiene una prenotazione.');
        }

        return self::booking($payload, $trigger);
    }

    /**
     * @param  array<array-key, mixed>  $booking
     *
     * @throws InvalidArgumentException
     */
    public static function fromApi(array $booking): BookingInput
    {
        return self::booking($booking, null);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function booking(array $data, ?string $trigger): BookingInput
    {
        $uid = self::string($data, 'uid') ?? self::string($data, 'bookingId') ?? self::string($data, 'id');
        if ($uid === null) {
            throw new InvalidArgumentException('La prenotazione non ha un identificativo.');
        }

        $start = self::string($data, 'startTime') ?? self::string($data, 'start');
        $end = self::string($data, 'endTime') ?? self::string($data, 'end');
        if ($start === null || $end === null) {
            throw new InvalidArgumentException('La prenotazione non ha un orario.');
        }

        $attendee = null;
        $attendees = $data['attendees'] ?? null;
        if (is_array($attendees) && isset($attendees[0]) && is_array($attendees[0])) {
            $attendee = $attendees[0];
        }
        $eventType = $data['eventType'] ?? null;
        $eventTypeName = is_array($eventType) ? (self::string($eventType, 'slug') ?? self::string($eventType, 'title')) : (is_string($eventType) ? $eventType : self::string($data, 'eventTypeSlug') ?? self::string($data, 'type'));
        $responses = $data['responses'] ?? null;
        $notes = self::string($data, 'description') ?? self::string($data, 'additionalNotes');
        if ($notes === null && is_array($responses) && isset($responses['notes'])) {
            $value = $responses['notes'];
            $notes = is_array($value) ? self::string($value, 'value') : (is_string($value) ? $value : null);
        }

        try {
            $startsAt = CarbonImmutable::parse($start);
            $endsAt = CarbonImmutable::parse($end);
        } catch (Throwable) {
            throw new InvalidArgumentException('L\'orario della prenotazione non si legge.');
        }

        $email = $attendee === null ? null : self::string($attendee, 'email');
        $location = self::string($data, 'location');
        if ($location !== null && str_starts_with($location, 'integrations:')) {
            $location = substr($location, strlen('integrations:'));
        }

        return new BookingInput(
            uid: $uid,
            title: self::string($data, 'title') ?? 'Appuntamento',
            eventType: $eventTypeName,
            status: BookingStatus::fromCalCom(self::string($data, 'status'), $trigger),
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $attendee === null ? null : (self::string($attendee, 'timeZone') ?? self::string($attendee, 'timezone')),
            attendeeName: $attendee === null ? null : self::string($attendee, 'name'),
            attendeeEmail: $email === null ? null : mb_strtolower(trim($email)),
            location: $location,
            notes: $notes,
            cancellationReason: self::string($data, 'cancellationReason'),
            payload: self::stringKeys($data),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
