<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Support\Shape;
use InvalidArgumentException;

/**
 * All numbering series. By default one shared series serves invoices and
 * credit notes; separate series can be added.
 */
final readonly class NumberingSeries
{
    public const string DEFAULT_SERIES = 'invoices';

    /** @var list<Series> */
    public array $series;

    /**
     * @param  iterable<Series>  $series
     */
    public function __construct(iterable $series)
    {
        $list = [];
        foreach ($series as $item) {
            if (isset($list[$item->code])) {
                throw new InvalidArgumentException("Series \"{$item->code}\" is defined twice.");
            }
            $list[$item->code] = $item;
        }

        if ($list === []) {
            throw new InvalidArgumentException('At least one numbering series is needed.');
        }

        $this->series = array_values($list);
    }

    public static function default(): self
    {
        return new self([new Series(self::DEFAULT_SERIES, 'Fatture e note di credito')]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(Series::fromArray(...), Shape::listOfArrays($data, 'series')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['series' => array_map(fn (Series $series): array => $series->toArray(), $this->series)];
    }

    public function find(string $code): ?Series
    {
        foreach ($this->series as $series) {
            if ($series->code === $code) {
                return $series;
            }
        }

        return null;
    }

    public function get(string $code): Series
    {
        return $this->find($code) ?? throw new InvalidArgumentException("Unknown numbering series \"{$code}\".");
    }
}
