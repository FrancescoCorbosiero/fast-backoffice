<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Data\JobRunData;
use App\Modules\Scheduling\Enums\RunTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $job_key
 * @property RunTrigger $trigger
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?int $exit_code
 * @property string $output
 */
class JobRun extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => RunTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'exit_code' => 'integer',
        ];
    }

    public function toData(): JobRunData
    {
        return new JobRunData(
            id: $this->id,
            jobKey: $this->job_key,
            trigger: $this->trigger,
            startedAt: $this->started_at,
            finishedAt: $this->finished_at,
            exitCode: $this->exit_code,
            output: $this->output,
        );
    }
}
