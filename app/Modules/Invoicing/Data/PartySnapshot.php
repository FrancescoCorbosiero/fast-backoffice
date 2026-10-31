<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Enums\ProfileType;
use App\Support\Shape;
use InvalidArgumentException;

/**
 * A party as it was when the document was issued: the seller from the
 * business profile, the customer from the contact. Frozen on the
 * document, so later edits never change an issued document.
 */
final readonly class PartySnapshot
{
    public function __construct(
        public string $name,
        public bool $isCompany,
        public ?string $firstName,
        public ?string $lastName,
        public ?VatNumber $vatNumber,
        public ?string $taxCode,
        public ?string $street,
        public ?string $streetNumber,
        public ?string $postcode,
        public ?string $city,
        public ?string $province,
        public string $country,
        public ?string $email,
        public ?string $pec,
        public ?string $sdiCode = null,
        public ?string $phone = null,
        public ?string $iban = null,
    ) {}

    public static function ofSeller(BusinessProfile $profile): self
    {
        $vat = $profile->vatNumber === null ? null : self::vat('IT', $profile->vatNumber);

        return new self(
            name: $profile->displayName(),
            isCompany: $profile->type === ProfileType::Company,
            firstName: $profile->type === ProfileType::Company ? null : $profile->firstName,
            lastName: $profile->type === ProfileType::Company ? null : $profile->lastName,
            vatNumber: $vat,
            taxCode: $profile->taxCode,
            street: $profile->street,
            streetNumber: $profile->streetNumber,
            postcode: $profile->postcode,
            city: $profile->city,
            province: $profile->province,
            country: $profile->country,
            email: $profile->email,
            pec: $profile->pec,
            phone: $profile->phone,
            iban: $profile->iban,
        );
    }

    public static function ofCustomer(ContactData $contact): self
    {
        return new self(
            name: $contact->displayName,
            isCompany: $contact->kind === ContactKind::Company,
            firstName: $contact->kind === ContactKind::Company ? null : $contact->firstName,
            lastName: $contact->kind === ContactKind::Company ? null : $contact->lastName,
            vatNumber: $contact->vatNumber,
            taxCode: $contact->taxCode,
            street: $contact->address->street,
            streetNumber: $contact->address->streetNumber,
            postcode: $contact->address->postcode,
            city: $contact->address->city,
            province: $contact->address->province,
            country: $contact->address->country,
            email: $contact->email,
            pec: $contact->pec,
            sdiCode: $contact->sdiCode,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $vat = Shape::nullableString($data, 'vat_number');

        return new self(
            name: Shape::string($data, 'name'),
            isCompany: Shape::bool($data, 'is_company'),
            firstName: Shape::nullableString($data, 'first_name'),
            lastName: Shape::nullableString($data, 'last_name'),
            vatNumber: $vat === null ? null : self::vat(Shape::nullableString($data, 'vat_country') ?? 'IT', $vat),
            taxCode: Shape::nullableString($data, 'tax_code'),
            street: Shape::nullableString($data, 'street'),
            streetNumber: Shape::nullableString($data, 'street_number'),
            postcode: Shape::nullableString($data, 'postcode'),
            city: Shape::nullableString($data, 'city'),
            province: Shape::nullableString($data, 'province'),
            country: Shape::nullableString($data, 'country') ?? 'IT',
            email: Shape::nullableString($data, 'email'),
            pec: Shape::nullableString($data, 'pec'),
            sdiCode: Shape::nullableString($data, 'sdi_code'),
            phone: Shape::nullableString($data, 'phone'),
            iban: Shape::nullableString($data, 'iban'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'is_company' => $this->isCompany,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'vat_country' => $this->vatNumber?->country,
            'vat_number' => $this->vatNumber?->number,
            'tax_code' => $this->taxCode,
            'street' => $this->street,
            'street_number' => $this->streetNumber,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'email' => $this->email,
            'pec' => $this->pec,
            'sdi_code' => $this->sdiCode,
            'phone' => $this->phone,
            'iban' => $this->iban,
        ];
    }

    public function isForeign(): bool
    {
        return $this->country !== 'IT';
    }

    /**
     * What FatturaPA needs of a party: a name, a fiscal identifier, and an
     * address with street, postcode and city.
     *
     * @return list<string> the missing fields, empty when complete
     */
    public function missingForFatturaPa(): array
    {
        $missing = [];

        if ($this->name === '') {
            $missing[] = 'name';
        }
        if ($this->vatNumber === null && $this->taxCode === null) {
            $missing[] = 'vat_number';
        }
        foreach (['street', 'postcode', 'city'] as $field) {
            if ($this->{$field} === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function addressLine(): string
    {
        $parts = array_filter([
            trim(($this->street ?? '').' '.($this->streetNumber ?? '')),
            trim(($this->postcode ?? '').' '.($this->city ?? '').($this->province !== null ? " ({$this->province})" : '')),
            $this->country === 'IT' ? '' : $this->country,
        ], fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    private static function vat(string $country, string $number): ?VatNumber
    {
        try {
            return new VatNumber($country, $number);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
