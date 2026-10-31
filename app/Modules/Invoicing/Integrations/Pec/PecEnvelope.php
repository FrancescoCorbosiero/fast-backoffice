<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

use Carbon\CarbonImmutable;

/**
 * A PEC message opened: the provider's certification data (daticert.xml)
 * and what the original message carried (postacert.eml). A plain message
 * that is not a PEC envelope has no certification and its own attachments.
 */
final readonly class PecEnvelope
{
    /**
     * @param  ?string  $from  who the PEC provider says sent the envelope
     * @param  ?string  $originalFrom  who wrote the original message inside (daticert's mittente, or the inner From)
     * @param  list<PecAttachment>  $attachments  the original message's attachments (daticert.xml and postacert.eml excluded)
     */
    public function __construct(
        public string $messageId,
        public ?string $from,
        public ?string $originalFrom,
        public string $subject,
        public ?CarbonImmutable $date,
        public ?string $certificationType,
        public ?string $certificationError,
        public ?string $referencedMessageId,
        public array $attachments,
    ) {}

    /**
     * The provider's receipt for a message we sent (accepted by our provider,
     * or delivered to the recipient's mailbox).
     */
    public function isTransportReceipt(): bool
    {
        return in_array($this->certificationType, ['accettazione', 'avvenuta-consegna', 'presa-in-carico'], true);
    }

    public function isTransportError(): bool
    {
        return in_array($this->certificationType, ['errore-consegna', 'preavviso-errore-consegna', 'non-accettazione', 'rilevazione-virus'], true);
    }

    public function attachment(string $name): ?PecAttachment
    {
        foreach ($this->attachments as $attachment) {
            if (strcasecmp($attachment->name, $name) === 0) {
                return $attachment;
            }
        }

        return null;
    }
}
