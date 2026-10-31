<?php

declare(strict_types=1);

use App\Modules\Calendar\Actions\RecordBooking;
use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Events\BookingCancelled;
use App\Modules\Calendar\Events\BookingRecorded;
use App\Modules\Calendar\Filament\Resources\Bookings\Pages\ListBookings;
use App\Modules\Calendar\Filament\Widgets\ContactBookingsTable;
use App\Modules\Calendar\Filament\Widgets\UpcomingBookingsWidget;
use App\Modules\Calendar\Integrations\CalComPayload;
use App\Modules\Calendar\Integrations\WebhookSignature;
use App\Modules\Calendar\Jobs\SyncBookings;
use App\Modules\Calendar\Models\Booking;
use App\Modules\Calendar\Models\WebhookEvent;
use App\Modules\Calendar\Queries\Bookings;
use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Contacts\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $overrides
 */
function calcomWebhook(string $trigger, array $overrides = []): string
{
    return json_encode([
        'triggerEvent' => $trigger,
        'createdAt' => '2026-03-15T09:00:00Z',
        'payload' => [
            'uid' => 'abc123uid',
            'title' => 'Call conoscitiva tra Mario Rossi e Giulia Verdi',
            'startTime' => '2026-03-20T09:00:00Z',
            'endTime' => '2026-03-20T09:30:00Z',
            'type' => 'call-30',
            'status' => 'ACCEPTED',
            'location' => 'integrations:google:meet',
            'attendees' => [['name' => 'Giulia Verdi', 'email' => 'Giulia@Verdi.example', 'timeZone' => 'Europe/Rome']],
            'responses' => ['notes' => ['value' => 'Vorrei un e-commerce']],
            ...$overrides,
        ],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    config()->set('calcom.webhook_secret', 'whsec_test');
    $this->travelTo('2026-03-15 10:00:00');
});

it('accepts a signed webhook once, creates the booking and links the contact by email', function (): void {
    Event::fake([BookingRecorded::class]);
    $contact = Contact::factory()->create(['email' => 'giulia@verdi.example']);
    $body = calcomWebhook('BOOKING_CREATED');

    $response = $this->call('POST', '/webhooks/calcom', [], [], [], ['HTTP_X_CAL_SIGNATURE_256' => WebhookSignature::sign($body, 'whsec_test'), 'CONTENT_TYPE' => 'application/json'], $body);

    $response->assertOk()->assertJsonPath('outcome', 'processed');
    $booking = Booking::query()->sole();
    expect($booking->external_uid)->toBe('abc123uid')
        ->and($booking->status)->toBe(BookingStatus::Accepted)
        ->and($booking->starts_at->toIso8601String())->toBe('2026-03-20T09:00:00+00:00')
        ->and($booking->attendee_email)->toBe('giulia@verdi.example')
        ->and($booking->contact_id)->toBe($contact->id)
        ->and($booking->event_type)->toBe('call-30')
        ->and($booking->location)->toBe('google:meet')
        ->and($booking->notes)->toBe('Vorrei un e-commerce')
        ->and($booking->source)->toBe('webhook');
    Event::assertDispatchedTimes(BookingRecorded::class, 1);

    $again = $this->call('POST', '/webhooks/calcom', [], [], [], ['HTTP_X_CAL_SIGNATURE_256' => WebhookSignature::sign($body, 'whsec_test')], $body);
    $again->assertOk()->assertJsonPath('outcome', 'duplicate');
    expect(Booking::query()->count())->toBe(1)->and(WebhookEvent::query()->count())->toBe(1);
});

it('refuses unsigned or badly signed deliveries, and when no secret is configured', function (): void {
    $body = calcomWebhook('BOOKING_CREATED');

    $this->call('POST', '/webhooks/calcom', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
    $this->call('POST', '/webhooks/calcom', [], [], [], ['HTTP_X_CAL_SIGNATURE_256' => 'deadbeef'], $body)->assertStatus(401);

    config()->set('calcom.webhook_secret', null);
    $this->call('POST', '/webhooks/calcom', [], [], [], ['HTTP_X_CAL_SIGNATURE_256' => WebhookSignature::sign($body, 'whsec_test')], $body)->assertStatus(401);

    expect(Booking::query()->count())->toBe(0)->and(WebhookEvent::query()->count())->toBe(0);
});

it('reschedules and cancels through later webhooks, and ignores what it does not know', function (): void {
    Event::fake([BookingCancelled::class]);
    $sign = fn (string $body): array => ['HTTP_X_CAL_SIGNATURE_256' => WebhookSignature::sign($body, 'whsec_test')];

    $this->call('POST', '/webhooks/calcom', [], [], [], $sign(calcomWebhook('BOOKING_CREATED')), calcomWebhook('BOOKING_CREATED'))->assertOk();
    $moved = calcomWebhook('BOOKING_RESCHEDULED', ['startTime' => '2026-03-21T14:00:00Z', 'endTime' => '2026-03-21T14:30:00Z']);
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($moved), $moved)->assertOk()->assertJsonPath('outcome', 'processed');

    $booking = Booking::query()->sole();
    expect($booking->starts_at->toIso8601String())->toBe('2026-03-21T14:00:00+00:00')->and($booking->status)->toBe(BookingStatus::Rescheduled);

    $cancelled = calcomWebhook('BOOKING_CANCELLED', ['status' => 'CANCELLED', 'cancellationReason' => 'Impegno improvviso']);
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($cancelled), $cancelled)->assertOk();
    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)->and($booking->cancellation_reason)->toBe('Impegno improvviso');
    Event::assertDispatchedTimes(BookingCancelled::class, 1);

    $ping = json_encode(['triggerEvent' => 'PING', 'payload' => []], JSON_THROW_ON_ERROR);
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($ping), $ping)->assertOk()->assertJsonPath('outcome', 'ignored');
    $meeting = json_encode(['triggerEvent' => 'MEETING_ENDED', 'payload' => ['uid' => 'x']], JSON_THROW_ON_ERROR);
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($meeting), $meeting)->assertOk()->assertJsonPath('outcome', 'ignored');
    $broken = json_encode(['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['title' => 'senza uid']], JSON_THROW_ON_ERROR);
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($broken), $broken)->assertOk()->assertJsonPath('outcome', 'ignored');
    $notJson = 'nope';
    $this->call('POST', '/webhooks/calcom', [], [], [], $sign($notJson), $notJson)->assertOk()->assertJsonPath('outcome', 'ignored');

    expect(Booking::query()->count())->toBe(1)->and(WebhookEvent::query()->count())->toBe(7);
});

it('syncs bookings from the API, idempotently, reading the v2 shape too', function (): void {
    config()->set('calcom.api_key', 'cal_test_key');
    Http::fake([
        'api.cal.com/v2/bookings*' => Http::response(['status' => 'success', 'data' => [
            ['uid' => 'v2-one', 'title' => 'Demo', 'status' => 'accepted', 'start' => '2026-03-22T10:00:00.000Z', 'end' => '2026-03-22T11:00:00.000Z', 'eventType' => ['id' => 1, 'slug' => 'demo-60'], 'attendees' => [['name' => 'Marco', 'email' => 'marco@example.test', 'timeZone' => 'Europe/Rome']], 'location' => 'https://meet.example'],
            ['uid' => 'abc123uid', 'title' => 'Call conoscitiva', 'status' => 'cancelled', 'start' => '2026-03-20T09:00:00.000Z', 'end' => '2026-03-20T09:30:00.000Z', 'attendees' => []],
            ['title' => 'senza uid'],
        ]]),
    ]);
    Booking::factory()->create(['external_uid' => 'abc123uid', 'source' => 'webhook']);

    $result = app(SyncBookings::class)->run();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer cal_test_key') && $request->hasHeader('cal-api-version'));
    expect($result->succeeded())->toBeTrue()->and($result->output)->toContain('2 prenotazioni')
        ->and(Booking::query()->count())->toBe(2)
        ->and(app(Bookings::class)->byUid('v2-one')?->eventType)->toBe('demo-60')
        ->and(app(Bookings::class)->byUid('abc123uid')?->status)->toBe(BookingStatus::Cancelled)
        ->and(app(Bookings::class)->byUid('abc123uid')?->source)->toBe('sync');

    expect(app(SyncBookings::class)->run()->succeeded())->toBeTrue()->and(Booking::query()->count())->toBe(2);

    config()->set('calcom.api_key', null);
    expect(app(SyncBookings::class)->run()->output)->toContain('non configurato');

    config()->set('calcom.api_key', 'cal_test_key');
    config()->set('calcom.api_url', 'https://broken.cal.test/v2');
    Http::fake(['broken.cal.test/*' => Http::response('nope', 500)]);
    expect(app(SyncBookings::class)->run()->succeeded())->toBeFalse();
});

it('lists what is coming, follows merged contacts, and shows up in the panel', function (): void {
    signInAsOwner();
    $contact = Contact::factory()->create(['email' => 'giulia@verdi.example']);
    $other = Contact::factory()->create();
    Booking::factory()->create(['external_uid' => 'past', 'starts_at' => '2026-03-10 10:00:00', 'ends_at' => '2026-03-10 10:30:00', 'contact_id' => $contact->id]);
    $soon = Booking::factory()->create(['external_uid' => 'soon', 'starts_at' => '2026-03-16 09:00:00', 'ends_at' => '2026-03-16 09:30:00', 'contact_id' => $contact->id, 'attendee_name' => 'Giulia Verdi']);
    Booking::factory()->create(['external_uid' => 'later', 'starts_at' => '2026-04-16 09:00:00', 'ends_at' => '2026-04-16 09:30:00']);
    Booking::factory()->create(['external_uid' => 'off', 'starts_at' => '2026-03-17 09:00:00', 'ends_at' => '2026-03-17 09:30:00', 'status' => BookingStatus::Cancelled]);

    $upcoming = app(Bookings::class)->upcoming(CarbonImmutable::now());
    expect(array_map(fn ($b) => $b->uid, $upcoming))->toBe(['soon', 'later'])
        ->and($upcoming[0]->isUpcoming(CarbonImmutable::now()))->toBeTrue()
        ->and(app(Bookings::class)->forContact($contact->id))->toHaveCount(2);

    $this->get('/admin/bookings')->assertOk();
    $this->get("/admin/bookings/{$soon->id}")->assertOk()->assertSee('Call conoscitiva')->assertSee('Giulia Verdi');
    Livewire::test(ListBookings::class)->assertCanSeeTableRecords([$soon]);
    Livewire::test(UpcomingBookingsWidget::class)->assertSee('prossimi 7 giorni')->assertSee('Giulia Verdi');
    $this->get("/admin/contacts/{$contact->id}")->assertOk()->assertSee('Appuntamenti');
    Livewire::test(ContactBookingsTable::class, ['contactId' => $contact->id])->assertSee('Call conoscitiva');

    app(MergeContacts::class)->merge($other->id, $contact->id);
    expect(Booking::query()->where('contact_id', $other->id)->count())->toBe(2);

    $recorded = app(RecordBooking::class)->record(CalComPayload::fromApi(['uid' => 'new', 'title' => 'x', 'start' => '2026-05-01T10:00:00Z', 'end' => '2026-05-01T10:30:00Z', 'attendees' => [['email' => $other->email]]]), 'sync');
    expect($recorded->contactId)->toBe($other->id);
});
