<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Data;

use App\Support\Shape;

final readonly class Address
{
    public function __construct(
        public ?string $street = null,
        public ?string $streetNumber = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $province = null,
        public string $country = 'IT',
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $country = strtoupper(Shape::nullableString($data, 'country') ?? 'IT');
        $province = Shape::nullableString($data, 'province');

        return new self(
            street: Shape::nullableString($data, 'street'),
            streetNumber: Shape::nullableString($data, 'street_number'),
            postcode: Shape::nullableString($data, 'postcode'),
            city: Shape::nullableString($data, 'city'),
            province: $province === null ? null : strtoupper($province),
            country: $country,
        );
    }

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'street_number' => $this->streetNumber,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
        ];
    }

    /**
     * What FatturaPA needs of an address.
     */
    public function isComplete(): bool
    {
        return $this->street !== null && $this->postcode !== null && $this->city !== null;
    }

    public function isEmpty(): bool
    {
        return $this->street === null && $this->postcode === null && $this->city === null && $this->province === null;
    }

    public function oneLine(): string
    {
        $parts = array_filter([
            trim(($this->street ?? '').' '.($this->streetNumber ?? '')),
            trim(($this->postcode ?? '').' '.($this->city ?? '').($this->province !== null ? " ({$this->province})" : '')),
            $this->country === 'IT' ? '' : $this->country,
        ], fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }
}
