<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Kernel\Reference;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use Carbon\CarbonImmutable;

/**
 * A request as the screens and the portal read it. Names are snapshots
 * taken when the request was made (like a quote's customer name), so a
 * list never queries Contacts or Websites per row.
 */
final readonly class ChangeRequestData
{
    /**
     * @param  list<string>  $touchedPaths  files the run changed, relative to the repository
     * @param  array<string, string>  $generatedFiles  path → content, for onboarding
     * @param  list<ChangeRequestAttachmentData>  $attachments  the images, in the client's order; removed ones included
     */
    public function __construct(
        public int $id,
        public int $contactId,
        public int $websiteId,
        public string $clientName,
        public string $websiteName,
        public ChangeRequestKind $kind,
        public ChangeRequestStatus $status,
        public string $requestText,
        public string $branch,
        public string $previewSlug,
        public ?string $previewUrl,
        public string $log,
        public ?string $error,
        public ?string $clientError,
        public array $touchedPaths,
        public array $generatedFiles,
        public ?DecidedBy $decidedBy,
        public ?string $decisionReason,
        public ?CarbonImmutable $decidedAt,
        public ?CarbonImmutable $deployStartedAt,
        public ?int $costCents,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public array $attachments = [],
    ) {}

    public function reference(): Reference
    {
        return Reference::to('change_request', $this->id);
    }

    public function isOnboarding(): bool
    {
        return $this->kind === ChangeRequestKind::Onboarding;
    }

    public function awaitsDecision(): bool
    {
        return $this->status->awaitsDecision();
    }

    /**
     * The images whose files are still there, in order: what a run copies
     * into the checkout and what the prompt lists.
     *
     * @return list<ChangeRequestAttachmentData>
     */
    public function availableAttachments(): array
    {
        return array_values(array_filter($this->attachments, fn (ChangeRequestAttachmentData $attachment): bool => ! $attachment->isRemoved()));
    }

    public function title(): string
    {
        return ($this->isOnboarding() ? 'Onboarding' : 'Richiesta').' #'.$this->id.' · '.$this->websiteName;
    }
}
