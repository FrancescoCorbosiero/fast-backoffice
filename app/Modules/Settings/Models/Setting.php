<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * One JSON document per settings group, keyed by name.
 *
 * @property string $key
 * @property array<string, mixed> $value
 */
class Setting extends Model
{
    public const null CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        return self::query()->find($key)?->value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function write(string $key, array $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => Date::now()]);
    }
}
