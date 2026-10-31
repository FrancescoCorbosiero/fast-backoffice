<?php

declare(strict_types=1);

use App\Kernel\Reference;

it('round-trips through its string form', function (string $string, string $type, string $key): void {
    $reference = Reference::fromString($string);

    expect($reference->type)->toBe($type)
        ->and($reference->key)->toBe($key)
        ->and($reference->toString())->toBe($string)
        ->and((string) $reference)->toBe($string)
        ->and($reference->is($type))->toBeTrue()
        ->and($reference->equals(Reference::to($type, $key)))->toBeTrue();
})->with([
    ['invoice:123', 'invoice', '123'],
    ['domain_renewal:7:2027', 'domain_renewal', '7:2027'],
    ['supplier_invoice:IT01234567890-00001', 'supplier_invoice', 'IT01234567890-00001'],
]);

it('refuses malformed references', function (string $string): void {
    expect(fn () => Reference::fromString($string))->toThrow(InvalidArgumentException::class);
})->with(['invoice', 'Invoice:1', 'invoice:', ':1', 'invoice:a b', 'invoice::1']);
