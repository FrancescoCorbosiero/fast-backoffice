<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Modules\Settings\Enums\ProfileType;
use App\Modules\Settings\Enums\RegimeFiscale;
use App\Support\Shape;

/**
 * The owner's fiscal identity: the cedente/prestatore of every invoice.
 * Invoicing freezes a snapshot of it on each document at issue.
 */
final readonly class BusinessProfile
{
    public function __construct(
        public ProfileType $type = ProfileType::Individual,
        public ?string $businessName = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $taxCode = null,
        public ?string $vatNumber = null,
        public RegimeFiscale $regime = RegimeFiscale::Forfettario,
        public ?string $street = null,
        public ?string $streetNumber = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $province = null,
        public string $country = 'IT',
        public ?string $email = null,
        public ?string $pec = null,
        public ?string $phone = null,
        public ?string $iban = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: ProfileType::tryFrom(Shape::string($data, 'type')) ?? ProfileType::Individual,
            businessName: Shape::nullableString($data, 'business_name'),
            firstName: Shape::nullableString($data, 'first_name'),
            lastName: Shape::nullableString($data, 'last_name'),
            taxCode: self::upper(Shape::nullableString($data, 'tax_code')),
            vatNumber: Shape::nullableString($data, 'vat_number'),
            regime: RegimeFiscale::tryFrom(Shape::string($data, 'regime')) ?? RegimeFiscale::Forfettario,
            street: Shape::nullableString($data, 'street'),
            streetNumber: Shape::nullableString($data, 'street_number'),
            postcode: Shape::nullableString($data, 'postcode'),
            city: Shape::nullableString($data, 'city'),
            province: self::upper(Shape::nullableString($data, 'province')),
            country: self::upper(Shape::nullableString($data, 'country')) ?? 'IT',
            email: Shape::nullableString($data, 'email'),
            pec: Shape::nullableString($data, 'pec'),
            phone: Shape::nullableString($data, 'phone'),
            iban: self::upper(Shape::nullableString($data, 'iban')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'business_name' => $this->businessName,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'tax_code' => $this->taxCode,
            'vat_number' => $this->vatNumber,
            'regime' => $this->regime->value,
            'street' => $this->street,
            'street_number' => $this->streetNumber,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'email' => $this->email,
            'pec' => $this->pec,
            'phone' => $this->phone,
            'iban' => $this->iban,
        ];
    }

    /**
     * The name that goes on documents: the company, or the person.
     */
    public function displayName(): string
    {
        if ($this->type === ProfileType::Company) {
            return $this->businessName ?? '';
        }

        return $this->businessName ?? trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    /**
     * Whether an invoice can be issued from this profile: a name, a fiscal
     * identifier and an address are the minimum FatturaPA accepts.
     */
    public function isComplete(): bool
    {
        return $this->displayName() !== ''
            && ($this->vatNumber !== null || $this->taxCode !== null)
            && $this->street !== null
            && $this->postcode !== null
            && $this->city !== null;
    }

    private static function upper(?string $value): ?string
    {
        return $value === null ? null : strtoupper(str_replace(' ', '', $value));
    }
}
