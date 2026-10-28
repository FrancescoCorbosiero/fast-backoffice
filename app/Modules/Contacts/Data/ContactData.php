<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Data;

use App\Kernel\Fiscal\VatNumber;
use App\Kernel\Reference;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use Carbon\CarbonImmutable;

/**
 * A contact as other modules see it.
 */
final readonly class ContactData
{
    /**
     * @param  list<ContactRole>  $roles
     */
    public function __construct(
        public int $id,
        public ContactKind $kind,
        public string $displayName,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $businessName,
        public ?string $email,
        public ?string $phone,
        public ?VatNumber $vatNumber,
        public ?string $taxCode,
        public ?string $sdiCode,
        public ?string $pec,
        public Address $address,
        public array $roles,
        public bool $emailConsent,
        public ?CarbonImmutable $emailSuppressedAt,
        public ?string $emailSuppressionReason,
        public ?string $notes,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('contact', $this->id);
    }

    public function hasRole(ContactRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isEmailSuppressed(): bool
    {
        return $this->emailSuppressedAt !== null;
    }

    /**
     * Whether a marketing email may go to this contact: an address, consent, no suppression.
     */
    public function isMailable(): bool
    {
        return $this->email !== null && $this->emailConsent && ! $this->isEmailSuppressed();
    }

    /**
     * Whether the contact lives abroad for FatturaPA purposes.
     */
    public function isForeign(): bool
    {
        return $this->address->country !== 'IT';
    }

    public function sdiAddress(): SdiAddress
    {
        return SdiAddress::for($this);
    }

    /**
     * Whether an invoice can be issued to this contact.
     */
    public function hasFiscalIdentity(): bool
    {
        return $this->displayName !== ''
            && ($this->vatNumber !== null || $this->taxCode !== null)
            && $this->address->isComplete();
    }
}
