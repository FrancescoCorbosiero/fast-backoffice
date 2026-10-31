<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Modules\Invoicing\Enums\PecMessageKind;
use Carbon\CarbonImmutable;

/**
 * An SdI notification as the file says it (sdi.md §5): RC, NS, MC, AT.
 */
final readonly class SdiNotification
{
    /**
     * @param  list<array{code: string, description: string, hint: ?string}>  $errors
     */
    public function __construct(
        public PecMessageKind $kind,
        public string $rootElement,
        public ?string $sdiIdentifier,
        public ?string $fileName,
        public ?CarbonImmutable $receivedAt,
        public ?CarbonImmutable $deliveredAt,
        public ?string $description,
        public array $errors,
        public ?string $note,
    ) {}

    /**
     * @return list<string> "00404 — Fattura duplicata"
     */
    public function errorLines(): array
    {
        return array_map(fn (array $error): string => $error['code'].' — '.$error['description'], $this->errors);
    }
}
