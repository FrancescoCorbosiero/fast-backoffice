<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A sign-in link sent to a client: the token's hash, when it expires and
 * when it was used (once).
 *
 * @property int $id
 * @property int $contact_id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $used_at
 * @property CarbonImmutable $created_at
 */
class PortalSignIn extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
