<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Actions\SendToSdi;
use App\Modules\Invoicing\Queries\Invoices;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Settings\Queries\GetSdiSettings;
use Throwable;

/**
 * Automatic sending (sdi.md §3): every issued, unsent invoice goes to SdI
 * by PEC — when the switch is on and the mailbox is configured. One failure
 * doesn't stop the others.
 */
final readonly class SendIssuedInvoices implements ScheduledJob
{
    public function __construct(
        private GetSdiSettings $settings,
        private SendToSdi $send,
        private Invoices $invoices,
    ) {}

    public function key(): string
    {
        return 'invoicing.sdi-send';
    }

    public function label(): string
    {
        return 'Invio a SdI via PEC';
    }

    public function description(): string
    {
        return 'Invia a SdI le fatture emesse e non ancora inviate, se l\'invio automatico è acceso.';
    }

    public function defaultCron(): string
    {
        return '*/10 * * * *';
    }

    public function run(): JobResult
    {
        if (! $this->settings->get()->autoSend) {
            return JobResult::success('Invio automatico spento.');
        }
        if (! $this->send->isAvailable()) {
            return JobResult::failure('La casella PEC non è configurata.');
        }

        $lines = [];
        $failures = 0;
        foreach ($this->invoices->toSend() as $invoice) {
            try {
                $sent = $this->send->send($invoice->id);
                $lines[] = $sent->title().': inviata ('.$sent->sdiFileName.')';
            } catch (Throwable $exception) {
                $failures++;
                $lines[] = $invoice->title().': errore, '.$exception->getMessage();
            }
        }

        $output = $lines === [] ? 'Nessuna fattura da inviare.' : implode("\n", $lines);

        return $failures === 0 ? JobResult::success($output) : JobResult::failure($output);
    }
}
