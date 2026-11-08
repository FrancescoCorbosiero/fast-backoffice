<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Kernel\Reference;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use App\Modules\Pipeline\Support\AttachmentStore;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Illuminate\Support\Facades\Date;

/**
 * Retention for the photos (docs/pipeline.md §10): the files of requests
 * deployed, rejected or failed more than keep_days ago are deleted, and
 * their rows stay, marked removed, for the audit trail. The same pass
 * deletes a file whose row is marked removed but that came back (a
 * restore from the backup) and, after a day, a file no row knows (an
 * upload that died before its row was written). Running it twice does
 * nothing more.
 */
final readonly class PruneAttachments implements ScheduledJob
{
    private const int ORPHAN_HOURS = 24;

    public function __construct(
        private AttachmentStore $store,
        private PipelineConfig $config,
        private RecordAuditEntry $audit,
    ) {}

    public function key(): string
    {
        return 'pipeline.prune-attachments';
    }

    public function label(): string
    {
        return 'Rimuovi le foto delle richieste concluse';
    }

    public function description(): string
    {
        return 'Cancella i file delle foto allegate alle richieste pubblicate, rifiutate o fallite da più di '.$this->config->attachmentKeepDays().' giorni; le righe restano, segnate come rimosse.';
    }

    public function defaultCron(): string
    {
        return '40 3 * * *';
    }

    public function run(): JobResult
    {
        $now = Date::now();
        $due = ChangeRequestAttachment::query()
            ->whereNull('removed_at')
            ->whereHas('changeRequest', fn ($query) => $query
                ->whereIn('status', [ChangeRequestStatus::Deployed->value, ChangeRequestStatus::Rejected->value, ChangeRequestStatus::Failed->value])
                ->where('finished_at', '<', $now->subDays($this->config->attachmentKeepDays())))
            ->orderBy('change_request_id')->orderBy('position')
            ->get();

        // The file goes first, then the mark: a crash in between leaves a
        // row that still says present for a file already gone (the route
        // answers 404 and the next run marks it), never the other way round.
        $removed = [];
        foreach ($due as $attachment) {
            $this->store->delete($attachment->stored_path);
            $attachment->forceFill(['removed_at' => $now])->save();
            $removed[$attachment->change_request_id] = ($removed[$attachment->change_request_id] ?? 0) + 1;
        }
        foreach ($removed as $requestId => $count) {
            $this->audit->record('pipeline.change_request.attachments_removed', Reference::to('change_request', $requestId), ['files' => $count, 'keep_days' => $this->config->attachmentKeepDays()]);
        }

        $back = 0;
        $orphans = 0;
        $known = ChangeRequestAttachment::query()->pluck('removed_at', 'stored_path')->all();
        foreach ($this->store->files() as $path => $modified) {
            if (array_key_exists($path, $known)) {
                if ($known[$path] !== null) {
                    $this->store->delete($path);
                    $back++;
                }
            } elseif ($modified < $now->subHours(self::ORPHAN_HOURS)->getTimestamp()) {
                $this->store->delete($path);
                $orphans++;
            }
        }

        $lines = [sprintf('%d foto rimosse da %d richieste.', array_sum($removed), count($removed))];
        if ($back > 0) {
            $lines[] = sprintf('%d foto già rimosse tornate sul disco, cancellate di nuovo.', $back);
        }
        if ($orphans > 0) {
            $lines[] = sprintf('%d file senza richiesta cancellati.', $orphans);
        }

        return JobResult::success(implode("\n", $lines));
    }
}
