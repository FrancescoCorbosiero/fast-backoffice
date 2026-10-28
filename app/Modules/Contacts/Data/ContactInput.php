<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Data;

use App\Kernel\Fiscal\CodiceFiscale;
use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Support\Shape;
use InvalidArgumentException;

/**
 * What it takes to create or update a contact. Built from a form or by
 * another module (converting a lead, importing a supplier invoice).
 */
final readonly class ContactInput
{
    /** @var list<ContactRole> */
    public array $roles;

    /**
     * @param  list<ContactRole>  $roles
     */
    public function __construct(
        public ContactKind $kind,
        array $roles,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $businessName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?VatNumber $vatNumber = null,
        public ?string $taxCode = null,
        public ?string $sdiCode = null,
        public ?string $pec = null,
        public Address $address = new Address,
        public bool $emailConsent = false,
        public ?string $notes = null,
    ) {
        $unique = [];
        foreach ($roles as $role) {
            $unique[$role->value] = $role;
        }

        $this->roles = array_values($unique);
    }

    /**
     * From form state: snake_case keys, scalars, "vat_number" with or
     * without a country prefix and "vat_country" beside it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $vat = Shape::nullableString($data, 'vat_number');
        $vatCountry = Shape::nullableString($data, 'vat_country') ?? 'IT';
        $taxCode = Shape::nullableString($data, 'tax_code');
        $sdiCode = Shape::nullableString($data, 'sdi_code');
        $email = Shape::nullableString($data, 'email');
        $pec = Shape::nullableString($data, 'pec');

        $roles = [];
        $rawRoles = $data['roles'] ?? [];
        foreach (is_array($rawRoles) ? $rawRoles : [] as $value) {
            $role = is_string($value) ? ContactRole::tryFrom($value) : null;
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return new self(
            kind: ContactKind::tryFrom(Shape::string($data, 'kind')) ?? ContactKind::Individual,
            roles: $roles,
            firstName: Shape::nullableString($data, 'first_name'),
            lastName: Shape::nullableString($data, 'last_name'),
            businessName: Shape::nullableString($data, 'business_name'),
            email: $email === null ? null : strtolower(trim($email)),
            phone: Shape::nullableString($data, 'phone'),
            vatNumber: $vat === null ? null : self::parseVat($vat, $vatCountry),
            taxCode: $taxCode === null ? null : CodiceFiscale::normalise($taxCode),
            sdiCode: $sdiCode === null ? null : strtoupper(trim($sdiCode)),
            pec: $pec === null ? null : strtolower(trim($pec)),
            address: Address::fromArray($data),
            emailConsent: Shape::bool($data, 'email_consent'),
            notes: Shape::nullableString($data, 'notes'),
        );
    }

    /**
     * The name on documents: the company, or the person.
     */
    public function displayName(): string
    {
        if ($this->kind === ContactKind::Company) {
            return trim($this->businessName ?? '');
        }

        $person = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return $person !== '' ? $person : trim($this->businessName ?? '');
    }

    private static function parseVat(string $value, string $country): ?VatNumber
    {
        try {
            return VatNumber::parse($value, $country);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
