<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use Carbon\CarbonImmutable;

/**
 * One file handed to the PEC provider, and what came back about it.
 */
final readonly class SdiTransmissionData
{
    public function __construct(
        public int $id,
        public int $invoiceId,
        public string $fileName,
        public string $messageId,
        public string $sentTo,
        public CarbonImmutable $sentAt,
        public ?CarbonImmutable $acceptedAt,
        public ?CarbonImmutable $deliveredToSdiAt,
        public ?string $transportError,
        public ?string $outcome,
        public ?CarbonImmutable $outcomeAt,
        public ?string $sdiIdentifier,
    ) {}

    /**
     * The transport's progress as one phrase.
     */
    public function transportStatus(): string
    {
        if ($this->transportError !== null) {
            return 'errore PEC: '.$this->transportError;
        }
        if ($this->deliveredToSdiAt !== null) {
            return 'consegnata a SdI il '.$this->deliveredToSdiAt->isoFormat('D MMM YYYY HH:mm');
        }
        if ($this->acceptedAt !== null) {
            return 'accettata dal gestore PEC il '.$this->acceptedAt->isoFormat('D MMM YYYY HH:mm');
        }

        return 'inviata il '.$this->sentAt->isoFormat('D MMM YYYY HH:mm');
    }
}
