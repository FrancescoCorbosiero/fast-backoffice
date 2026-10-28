<?php

declare(strict_types=1);

namespace App\Kernel\Numbering;

use InvalidArgumentException;

/**
 * Renders a document number the one way it appears everywhere: XML, PDF,
 * screens and emails. Patterns use {number}, {number:N} (zero-padded to N
 * digits), {year} and {yy}, e.g. "{number}/{year}" → "12/2026".
 */
final readonly class NumberFormat
{
    public function __construct(public string $pattern)
    {
        if (preg_match('/\{number(?::\d{1,2})?}/', $pattern) !== 1) {
            throw new InvalidArgumentException("A number format must contain {number}: \"{$pattern}\".");
        }

        $unknown = preg_replace('/\{(number(?::\d{1,2})?|year|yy)}/', '', $pattern);

        if (is_string($unknown) && preg_match('/[{}]/', $unknown) === 1) {
            throw new InvalidArgumentException("Unknown placeholder in number format \"{$pattern}\".");
        }
    }

    public function render(int $number, int $year): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException("Document numbers start at 1, got {$number}.");
        }

        $rendered = preg_replace_callback(
            '/\{(number(?::(\d{1,2}))?|year|yy)}/',
            fn (array $match): string => match ($match[1]) {
                'year' => (string) $year,
                'yy' => sprintf('%02d', $year % 100),
                default => isset($match[2])
                    ? str_pad((string) $number, (int) $match[2], '0', STR_PAD_LEFT)
                    : (string) $number,
            },
            $this->pattern,
        );

        return (string) $rendered;
    }
}
