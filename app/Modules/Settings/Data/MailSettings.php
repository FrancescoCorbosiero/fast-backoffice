<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Support\Shape;

/**
 * How campaigns go out (design.md §4.2, Mail): who they come from, how
 * fast, how long a failing send is retried, and when a temporary bounce
 * becomes a suppression. The SES keys are secrets in the environment.
 */
final readonly class MailSettings
{
    public function __construct(
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public int $ratePerSecond = 5,
        public int $retryHours = 24,
        public int $transientBouncesBeforeSuppression = 3,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $from = Shape::nullableString($data, 'from_address');
        $replyTo = Shape::nullableString($data, 'reply_to');

        return new self(
            fromAddress: $from === null ? null : mb_strtolower(trim($from)),
            fromName: Shape::nullableString($data, 'from_name'),
            replyTo: $replyTo === null ? null : mb_strtolower(trim($replyTo)),
            ratePerSecond: max(1, min(50, Shape::int($data, 'rate_per_second', 5))),
            retryHours: max(1, min(168, Shape::int($data, 'retry_hours', 24))),
            transientBouncesBeforeSuppression: max(1, min(10, Shape::int($data, 'transient_bounces_before_suppression', 3))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'reply_to' => $this->replyTo,
            'rate_per_second' => $this->ratePerSecond,
            'retry_hours' => $this->retryHours,
            'transient_bounces_before_suppression' => $this->transientBouncesBeforeSuppression,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->fromAddress !== null;
    }
}
