<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Data;

/**
 * How SdI routes an invoice to the customer (sdi.md §4): their 7-character
 * code; "0000000" plus their PEC; "0000000" alone for consumers, who read
 * it on the Agenzia's portal; "XXXXXXX" for customers abroad.
 */
final readonly class SdiAddress
{
    public const string PORTAL = '0000000';

    public const string ABROAD = 'XXXXXXX';

    public function __construct(
        public string $codiceDestinatario,
        public ?string $pec = null,
    ) {}

    public static function for(ContactData $contact): self
    {
        if ($contact->isForeign()) {
            return new self(self::ABROAD);
        }

        if ($contact->sdiCode !== null && $contact->sdiCode !== self::PORTAL) {
            return new self($contact->sdiCode);
        }

        return new self(self::PORTAL, $contact->pec);
    }

    public function isAbroad(): bool
    {
        return $this->codiceDestinatario === self::ABROAD;
    }

    public function isPortalOnly(): bool
    {
        return $this->codiceDestinatario === self::PORTAL && $this->pec === null;
    }
}
