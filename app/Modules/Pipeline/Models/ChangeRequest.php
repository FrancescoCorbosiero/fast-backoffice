<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Pipeline\Data\ChangeRequestAttachmentData;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Data\ConversationTurn;
use App\Modules\Pipeline\Database\Factories\ChangeRequestFactory;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $website_id
 * @property string $client_name
 * @property string $website_name
 * @property ChangeRequestKind $kind
 * @property ChangeRequestStatus $status
 * @property string $request_text
 * @property string $branch
 * @property string $preview_slug
 * @property ?string $preview_url
 * @property string $log
 * @property ?string $error
 * @property ?string $client_error
 * @property ?list<string> $touched_paths
 * @property ?array<string, string> $generated_files
 * @property ?DecidedBy $decided_by
 * @property ?string $decision_reason
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $deploy_started_at
 * @property ?int $cost_cents
 * @property ?CarbonImmutable $finished_at
 * @property ?list<array<array-key, mixed>> $conversation
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ChangeRequestAttachment> $attachments
 */
class ChangeRequest extends Model
{
    /** @use HasFactory<ChangeRequestFactory> */
    use HasFactory;

    public const int LOG_LIMIT = 20_000;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'log' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChangeRequestKind::class,
            'status' => ChangeRequestStatus::class,
            'touched_paths' => 'array',
            'generated_files' => 'array',
            'conversation' => 'array',
            'decided_by' => DecidedBy::class,
            'decided_at' => 'immutable_datetime',
            'deploy_started_at' => 'immutable_datetime',
            'cost_cents' => 'integer',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ChangeRequestFactory
    {
        return ChangeRequestFactory::new();
    }

    /**
     * The images the request carries, in the order the client gave them.
     *
     * @return HasMany<ChangeRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChangeRequestAttachment::class)->orderBy('position');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForContact(Builder $query, int $contactId): Builder
    {
        return $query->where('contact_id', $contactId);
    }

    /**
     * The one way the status moves: refused when the lifecycle doesn't
     * allow it, so a late or repeated job never moves a request backwards.
     * Reaching an end (deployed, rejected, failed) stamps finished_at, the
     * clock the attachments are kept by.
     *
     * @param  array<string, mixed>  $attributes  written together with the status
     *
     * @throws LogicException
     */
    public function moveTo(ChangeRequestStatus $next, array $attributes = []): void
    {
        if (! $this->status->canBecome($next)) {
            throw new LogicException("Change request {$this->id} is {$this->status->value}: it can't become {$next->value}.");
        }

        if ($next->isTerminal()) {
            $attributes['finished_at'] ??= Date::now();
        }

        $this->forceFill([...$attributes, 'status' => $next])->save();
    }

    public function appendLog(string $text): void
    {
        $log = $this->log === '' ? $text : $this->log."\n".$text;
        $this->forceFill(['log' => mb_substr($log, -self::LOG_LIMIT)])->save();
    }

    /**
     * Adds a turn to the request's exchange; the caller saves, together with the status.
     */
    public function addTurn(ConversationTurn $turn): void
    {
        $this->conversation = [...($this->conversation ?? []), $turn->toArray()];
    }

    public function toData(): ChangeRequestData
    {
        return new ChangeRequestData(
            id: $this->id,
            contactId: $this->contact_id,
            websiteId: $this->website_id,
            clientName: $this->client_name,
            websiteName: $this->website_name,
            kind: $this->kind,
            status: $this->status,
            requestText: $this->request_text,
            branch: $this->branch,
            previewSlug: $this->preview_slug,
            previewUrl: $this->preview_url,
            log: $this->log,
            error: $this->error,
            clientError: $this->client_error,
            touchedPaths: $this->touched_paths ?? [],
            generatedFiles: $this->generated_files ?? [],
            decidedBy: $this->decided_by,
            decisionReason: $this->decision_reason,
            decidedAt: $this->decided_at,
            deployStartedAt: $this->deploy_started_at,
            costCents: $this->cost_cents,
            createdAt: $this->created_at,
            updatedAt: $this->updated_at,
            attachments: array_values($this->attachments->map(fn (ChangeRequestAttachment $attachment): ChangeRequestAttachmentData => $attachment->toData())->all()),
            conversation: array_values(array_map(fn (array $turn): ConversationTurn => ConversationTurn::fromArray($turn), array_filter($this->conversation ?? [], is_array(...)))),
        );
    }
}
