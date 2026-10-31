<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Actions\ProcessPecMessage;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Integrations\Pec\PecMailbox;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Settings\Queries\GetSdiSettings;
use Throwable;

/**
 * The inbox (sdi.md §5): reads the unprocessed PEC messages, handles each
 * exactly once, flags it. A message that fails stays unflagged for the
 * next run only if it could not even be stored.
 */
final readonly class ReadPecInbox implements ScheduledJob
{
    public function __construct(
        private GetSdiSettings $settings,
        private PecMailbox $mailbox,
        private ProcessPecMessage $process,
    ) {}

    public function key(): string
    {
        return 'invoicing.pec-inbox';
    }

    public function label(): string
    {
        return 'Lettura casella PEC';
    }

    public function description(): string
    {
        return 'Legge ricevute, notifiche di SdI e fatture dei fornitori dalla casella PEC.';
    }

    public function defaultCron(): string
    {
        return '*/10 * * * *';
    }

    public function run(): JobResult
    {
        if (! $this->settings->get()->inboxEnabled) {
            return JobResult::success('Lettura della casella spenta.');
        }
        if (! $this->mailbox->isConfigured()) {
            return JobResult::failure('La casella PEC non è configurata.');
        }

        try {
            $messages = $this->mailbox->fetchUnprocessed();
        } catch (Throwable $exception) {
            return JobResult::failure('Lettura fallita: '.$exception->getMessage());
        }

        $lines = [];
        $review = 0;
        foreach ($messages as $message) {
            $result = $this->process->process($message);
            $this->mailbox->markProcessed($message);
            if ($result->outcome === PecMessageOutcome::Review || $result->outcome === PecMessageOutcome::Failed) {
                $review++;
            }
            $lines[] = sprintf('%s: %s%s', $result->kind->label(), $result->outcome->label(), $result->fileName !== null ? ' ('.$result->fileName.')' : '');
        }

        $output = $lines === [] ? 'Nessun messaggio nuovo.' : implode("\n", $lines);

        return $review === 0 ? JobResult::success($output) : JobResult::failure($output);
    }
}
