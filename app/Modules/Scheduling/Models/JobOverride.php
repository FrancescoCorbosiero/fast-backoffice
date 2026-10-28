<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the owner changed about a job defined in code: whether it runs,
 * and when. A null column means "as defined".
 *
 * @property string $job_key
 * @property ?bool $enabled
 * @property ?string $cron
 */
class JobOverride extends Model
{
    protected $primaryKey = 'job_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
