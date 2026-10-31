<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Jobs;

use App\Modules\Calendar\Actions\RecordBooking;
use App\Modules\Calendar\Integrations\CalComClient;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Every hour: the bookings of the last month and the next six, in case a
 * webhook was lost. Idempotent per uid.
 */
final readonly class SyncBookings implements ScheduledJob
{
    public function __construct(
        private CalComClient $client,
        private RecordBooking $record,
    ) {}

    public function key(): string
    {
        return 'calendar.sync';
    }

    public function label(): string
    {
        return 'Sincronizza Cal.com';
    }

    public function description(): string
    {
        return 'Legge le prenotazioni da Cal.com (un mese indietro, sei avanti) e aggiorna il calendario.';
    }

    public function defaultCron(): string
    {
        return '15 * * * *';
    }

    public function run(): JobResult
    {
        if (! $this->client->isConfigured()) {
            return JobResult::success('Cal.com non configurato (CALCOM_API_KEY).');
        }

        $now = Date::now();
        try {
            $bookings = $this->client->bookingsBetween($now->subMonth(), $now->addMonths(6));
        } catch (Throwable $exception) {
            return JobResult::failure('Sincronizzazione fallita: '.$exception->getMessage());
        }

        foreach ($bookings as $booking) {
            $this->record->record($booking, 'sync');
        }

        return JobResult::success(count($bookings).' prenotazioni sincronizzate.');
    }
}
