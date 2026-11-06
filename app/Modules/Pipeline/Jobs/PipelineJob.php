<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\FailChangeRequest;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Pipeline\Support\WorkspaceBusyException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * What the pipeline's queued jobs share: the pipeline queue and connection
 * (config/pipeline.php), the worker's timeout, one exception and the job
 * is done (no silent retry of a crashed run), a request marked failed when
 * the job dies, and a wait when another job holds the website's checkout.
 * Each job carries the request id only and hands the work to an Action;
 * the request's status checks and the deploy claim make a second dispatch
 * harmless.
 */
abstract class PipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BUSY_RETRY_SECONDS = 60;

    /** Attempts are only spent by a busy checkout; one exception ends the job. */
    public int $tries = 30;

    public int $maxExceptions = 1;

    public int $timeout;

    public function __construct(public readonly int $changeRequestId)
    {
        $config = app(PipelineConfig::class);
        $this->timeout = $config->workerTimeoutSeconds();
        $this->onConnection($config->queueConnection());
        $this->onQueue($config->queueName());
    }

    /**
     * Runs the action; a busy checkout sends the job back to the queue.
     *
     * @param  callable(): mixed  $work
     */
    protected function attempt(callable $work): void
    {
        try {
            $work();
        } catch (WorkspaceBusyException $exception) {
            if ($this->job === null) {
                throw $exception;
            }
            $this->release(self::BUSY_RETRY_SECONDS);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception === null ? 'Job interrotto.' : $exception::class.': '.$exception->getMessage();
        app(FailChangeRequest::class)->fail($this->changeRequestId, $message);
    }
}
