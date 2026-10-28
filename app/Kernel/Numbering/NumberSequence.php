<?php

declare(strict_types=1);

namespace App\Kernel\Numbering;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Gapless numbering: one counter per (series, year), kept in the
 * number_sequences table and advanced under a row lock inside the
 * caller's transaction. A rollback releases the number, so there are no
 * gaps. (Not a PostgreSQL SEQUENCE: those aren't transactional.)
 */
final readonly class NumberSequence
{
    public const string TABLE = 'number_sequences';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Allocate the next number of a series for a year. Must run inside a
     * transaction: the row stays locked until it commits or rolls back.
     *
     * @param  int  $openingNumber  the number a series starts from the first
     *                              time it's used in a year, so numbering begun in
     *                              another tool that year continues without a gap
     */
    public function next(string $series, int $year, int $openingNumber = 1): int
    {
        self::assertSeries($series);

        if ($openingNumber < 1) {
            throw new InvalidArgumentException('The opening number must be at least 1.');
        }

        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Numbers are allocated inside the issuing transaction.');
        }

        $this->db->table(self::TABLE)->insertOrIgnore([
            'series' => $series,
            'year' => $year,
            'last_number' => $openingNumber - 1,
        ]);

        /** @var object{last_number: int} $row */
        $row = $this->db->table(self::TABLE)
            ->where('series', $series)
            ->where('year', $year)
            ->lockForUpdate()
            ->sole();

        $number = $row->last_number + 1;

        $this->db->table(self::TABLE)
            ->where('series', $series)
            ->where('year', $year)
            ->update(['last_number' => $number]);

        return $number;
    }

    /**
     * The last number allocated, or null when the series hasn't been used
     * that year (so its opening number can still be changed).
     */
    public function last(string $series, int $year): ?int
    {
        self::assertSeries($series);

        /** @var object{last_number: int}|null $row */
        $row = $this->db->table(self::TABLE)
            ->where('series', $series)
            ->where('year', $year)
            ->first();

        return $row?->last_number;
    }

    private static function assertSeries(string $series): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $series) !== 1) {
            throw new InvalidArgumentException("\"{$series}\" is not a series code (lowercase letters, digits, _ and -).");
        }
    }
}
