<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Mail\Data\CampaignData;
use App\Modules\Mail\Database\Factories\CampaignFactory;
use App\Modules\Mail\Enums\CampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property ?string $preheader
 * @property string $body_html
 * @property list<string> $audience_roles
 * @property CampaignStatus $status
 * @property ?CarbonImmutable $scheduled_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience_roles' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return list<ContactRole>
     */
    public function roleEnums(): array
    {
        $roles = [];
        foreach ($this->audience_roles as $value) {
            $role = ContactRole::tryFrom($value);
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * @return array<string, int>
     */
    public function recipientCounts(): array
    {
        $counts = [];
        foreach ($this->recipients()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $status = $row->getAttribute('status');
            $n = $row->getAttribute('n');
            if (is_string($status) && is_numeric($n)) {
                $counts[$status] = (int) $n;
            } elseif ($status instanceof \App\Modules\Mail\Enums\RecipientStatus && is_numeric($n)) {
                $counts[$status->value] = (int) $n;
            }
        }

        return $counts;
    }

    public function toData(): CampaignData
    {
        return new CampaignData(
            id: $this->id,
            name: $this->name,
            subject: $this->subject,
            preheader: $this->preheader,
            bodyHtml: $this->body_html,
            audienceRoles: $this->roleEnums(),
            status: $this->status,
            scheduledAt: $this->scheduled_at,
            startedAt: $this->started_at,
            completedAt: $this->completed_at,
            counts: $this->recipientCounts(),
        );
    }
}
