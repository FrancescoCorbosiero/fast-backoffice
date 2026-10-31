<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Kernel\Numbering\NumberFormat;
use App\Support\Shape;
use InvalidArgumentException;

/**
 * A numbering series (design.md §5.2): its code, how its numbers are
 * rendered, and the number the first document of a year gets when
 * numbering started elsewhere that year.
 */
final readonly class Series
{
    /**
     * @param  array<int, int>  $openingNumbers  year → first number issued by this app
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $format = '{number}/{year}',
        public array $openingNumbers = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $code) !== 1) {
            throw new InvalidArgumentException("\"{$code}\" is not a series code (lowercase letters, digits, _ and -).");
        }

        new NumberFormat($format);

        foreach ($openingNumbers as $year => $number) {
            if ($year < 2000 || $year > 2200 || $number < 1) {
                throw new InvalidArgumentException("Opening number {$number} for {$year} in series {$code} is out of range.");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: Shape::string($data, 'code'),
            label: Shape::string($data, 'label'),
            format: Shape::nullableString($data, 'format') ?? '{number}/{year}',
            openingNumbers: Shape::intMap($data, 'opening_numbers'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'format' => $this->format,
            'opening_numbers' => $this->openingNumbers,
        ];
    }

    public function openingNumberFor(int $year): int
    {
        return $this->openingNumbers[$year] ?? 1;
    }

    public function render(int $number, int $year): string
    {
        return (new NumberFormat($this->format))->render($number, $year);
    }
}
