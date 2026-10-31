<?php

declare(strict_types=1);

use App\Kernel\Fiscal\CodiceFiscale;
use App\Kernel\Fiscal\PartitaIva;
use App\Kernel\Fiscal\VatNumber;

it('checks the Partita IVA check digit', function (string $value, bool $valid): void {
    expect(PartitaIva::isValid($value))->toBe($valid);
})->with([
    ['01234567897', true],
    ['IT01234567897', true],
    ['it 0123 4567 897', true],
    ['00000000000', true],
    ['01234567890', false],
    ['0123456789', false],
    ['012345678971', false],
    ['0123456789A', false],
    ['', false],
]);

it('checks the codice fiscale checksum, with omocodia and for companies', function (string $value, bool $valid): void {
    expect(CodiceFiscale::isValid($value))->toBe($valid);
})->with([
    ['RSSMRA85T10A562S', true],
    ['rssmra85t10a562s', true],
    ['MRTMTT91D08F205J', true],
    ['RSSMRA85T10A562T', false],
    ['RSSMRA85X10A562S', false],
    ['RSSMRA85T10A562', false],
    ['01234567897', true],
    ['01234567890', false],
    ['', false],
]);

it('accepts the omocodia variant, where digits become letters', function (): void {
    // The same person as RSSMRA85T10A562S, with the last digit of the
    // municipality code replaced: the check character is recomputed.
    $omocodia = 'RSSMRA85T10A56N';

    expect(CodiceFiscale::isValid($omocodia.CodiceFiscale::checkCharacter($omocodia)))->toBeTrue()
        ->and(CodiceFiscale::isValid($omocodia.'S'))->toBe(CodiceFiscale::checkCharacter($omocodia) === 'S');
});

it('computes the check character', function (): void {
    expect(CodiceFiscale::checkCharacter('RSSMRA85T10A562'))->toBe('S')
        ->and(CodiceFiscale::checkCharacter('MRTMTT91D08F205'))->toBe('J');
});

it('parses VAT numbers with or without a country prefix', function (string $input, string $country, string $number): void {
    $vat = VatNumber::parse($input);

    expect($vat->country)->toBe($country)
        ->and($vat->number)->toBe($number)
        ->and($vat->toString())->toBe($country.$number)
        ->and((string) $vat)->toBe($country.$number);
})->with([
    ['01234567897', 'IT', '01234567897'],
    ['IT01234567897', 'IT', '01234567897'],
    ['de 123456789', 'DE', '123456789'],
    ['FR12345678901', 'FR', '12345678901'],
    ['GR123456789', 'EL', '123456789'],
    ['ATU12345678', 'AT', 'U12345678'],
    ['NL123456789B01', 'NL', '123456789B01'],
]);

it('validates each country by its own format', function (string $country, string $number, bool $valid): void {
    expect((new VatNumber($country, $number))->isValid())->toBe($valid);
})->with([
    ['IT', '01234567897', true],
    ['IT', '01234567890', false],
    ['DE', '123456789', true],
    ['DE', '12345678', false],
    ['AT', 'U12345678', true],
    ['AT', '12345678', false],
    ['NL', '123456789B01', true],
    ['FR', 'XX123456789', true],
    ['IE', '1234567WA', true],
    ['ES', 'A12345678', true],
    ['XI', '123456789', true],
    ['CH', 'E123456789', true],
    ['US', '12-3456789', true],
    ['US', '!', false],
]);

it('knows Italian and EU numbers apart', function (): void {
    expect(VatNumber::parse('IT01234567897')->isItalian())->toBeTrue()
        ->and(VatNumber::parse('IT01234567897')->isEu())->toBeTrue()
        ->and(VatNumber::parse('DE123456789')->isItalian())->toBeFalse()
        ->and(VatNumber::parse('DE123456789')->isEu())->toBeTrue()
        ->and((new VatNumber('XI', '123456789'))->isEu())->toBeFalse()
        ->and((new VatNumber('US', '123'))->isEu())->toBeFalse()
        ->and(VatNumber::parse('IT01234567897')->equals(new VatNumber('it', '01234567897')))->toBeTrue();
});

it('refuses malformed input', function (): void {
    expect(fn () => new VatNumber('ITA', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VatNumber('IT', ''))->toThrow(InvalidArgumentException::class);
});
