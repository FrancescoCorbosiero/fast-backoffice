<?php

declare(strict_types=1);

namespace App\Kernel\Fiscal;

use InvalidArgumentException;
use Stringable;

/**
 * A VAT number with its country: "IT" + Partita IVA for Italy, the
 * national format for the other EU countries, anything alphanumeric
 * elsewhere. The XML's IdFiscaleIVA is exactly this pair.
 */
final readonly class VatNumber implements Stringable
{
    /** Number formats after the country prefix, EU members and Northern Ireland. */
    private const array EU_FORMATS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^[01]\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-Z]{1,2}|\d[A-Z+*]\d{5}[A-Z])$/',
        'IT' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'LV' => '/^\d{11}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SE' => '/^\d{12}$/',
        'SI' => '/^\d{8}$/',
        'SK' => '/^\d{10}$/',
        'XI' => '/^(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
    ];

    public string $country;

    public string $number;

    public function __construct(string $country, string $number)
    {
        $country = strtoupper(trim($country));
        $number = strtoupper(preg_replace('/[\s.\-]+/', '', $number) ?? '');

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException("\"{$country}\" is not a two-letter country code.");
        }

        if ($number === '') {
            throw new InvalidArgumentException('A VAT number cannot be empty.');
        }

        $this->country = $country === 'GR' ? 'EL' : $country;
        $this->number = $number;
    }

    /**
     * Parse "IT01234567897", "it 01234567897" or, without a prefix, a
     * number of the default country.
     */
    public static function parse(string $value, string $defaultCountry = 'IT'): self
    {
        $value = strtoupper(preg_replace('/[\s.\-]+/', '', $value) ?? '');

        if (preg_match('/^([A-Z]{2})(.+)$/', $value, $parts) === 1 && isset(self::EU_FORMATS[$parts[1] === 'GR' ? 'EL' : $parts[1]])) {
            return new self($parts[1], $parts[2]);
        }

        return new self($defaultCountry, $value);
    }

    public function isItalian(): bool
    {
        return $this->country === 'IT';
    }

    public function isEu(): bool
    {
        return isset(self::EU_FORMATS[$this->country]) && $this->country !== 'XI';
    }

    public function isValid(): bool
    {
        if ($this->isItalian()) {
            return PartitaIva::isValid($this->number);
        }

        $format = self::EU_FORMATS[$this->country] ?? '/^[A-Z0-9]{2,20}$/';

        return preg_match($format, $this->number) === 1;
    }

    public function equals(VatNumber $other): bool
    {
        return $this->country === $other->country && $this->number === $other->number;
    }

    public function toString(): string
    {
        return $this->country.$this->number;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
