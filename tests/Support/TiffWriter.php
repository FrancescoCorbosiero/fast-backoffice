<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Writes the TIFF structure EXIF is made of, for the image fixtures:
 * IFD0, the Exif and GPS sub-IFDs it points to, and IFD1 with a JPEG
 * thumbnail, laid out one after the other, in either byte order.
 */
final readonly class TiffWriter
{
    public const int BYTE = 1;

    public const int ASCII = 2;

    public const int SHORT = 3;

    public const int LONG = 4;

    public const int RATIONAL = 5;

    public const int UNDEFINED = 7;

    public function __construct(private bool $littleEndian) {}

    /**
     * @param  list<array{0: int, 1: int, 2: mixed}>  $ifd0  [tag, type, value], sorted by tag
     * @param  list<array{0: int, 1: int, 2: mixed}>  $exif
     * @param  list<array{0: int, 1: int, 2: mixed}>  $gps
     */
    public function build(array $ifd0, array $exif, array $gps, ?string $thumbnail = null): string
    {
        $pointers = [[0x8769, self::LONG, [0]], [0x8825, self::LONG, [0]]];
        $ifd1 = $thumbnail === null ? [] : [[0x0103, self::SHORT, [6]], [0x0201, self::LONG, [0]], [0x0202, self::LONG, [strlen($thumbnail)]]];

        $at0 = 8;
        $atExif = $at0 + $this->size([...$ifd0, ...$pointers]);
        $atGps = $atExif + $this->size($exif);
        $at1 = $atGps + $this->size($gps);
        $atThumbnail = $at1 + ($ifd1 === [] ? 0 : $this->size($ifd1));

        $pointers = [[0x8769, self::LONG, [$atExif]], [0x8825, self::LONG, [$atGps]]];
        if ($ifd1 !== []) {
            $ifd1[1] = [0x0201, self::LONG, [$atThumbnail]];
        }

        $out = ($this->littleEndian ? 'II' : 'MM').$this->u16(42).$this->u32($at0);
        $out .= $this->ifd([...$ifd0, ...$pointers], $at0, $ifd1 === [] ? 0 : $at1);
        $out .= $this->ifd($exif, $atExif, 0);
        $out .= $this->ifd($gps, $atGps, 0);
        if ($ifd1 !== []) {
            $out .= $this->ifd($ifd1, $at1, 0).$thumbnail;
        }

        return $out;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: mixed}>  $entries
     */
    private function size(array $entries): int
    {
        $size = 2 + 12 * count($entries) + 4;
        foreach ($entries as [, $type, $value]) {
            $bytes = strlen($this->value($type, $value));
            if ($bytes > 4) {
                $size += $bytes + ($bytes % 2);
            }
        }

        return $size;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: mixed}>  $entries
     */
    private function ifd(array $entries, int $at, int $next): string
    {
        $dataAt = $at + 2 + 12 * count($entries) + 4;
        $table = $this->u16(count($entries));
        $data = '';
        foreach ($entries as [$tag, $type, $value]) {
            $bytes = $this->value($type, $value);
            $table .= $this->u16($tag).$this->u16($type).$this->u32($this->count($type, $value));
            if (strlen($bytes) <= 4) {
                $table .= str_pad($bytes, 4, "\0");
            } else {
                $table .= $this->u32($dataAt + strlen($data));
                $data .= $bytes.(strlen($bytes) % 2 === 1 ? "\0" : '');
            }
        }

        return $table.$this->u32($next).$data;
    }

    private function value(int $type, mixed $value): string
    {
        if ($type === self::ASCII) {
            return (is_string($value) ? $value : '')."\0";
        }
        if ($type === self::BYTE || $type === self::UNDEFINED) {
            return is_string($value) ? $value : '';
        }

        $bytes = '';
        foreach (is_array($value) ? $value : [] as $item) {
            if ($type === self::RATIONAL && is_array($item) && is_int($item[0] ?? null) && is_int($item[1] ?? null)) {
                $bytes .= $this->u32($item[0]).$this->u32($item[1]);
            } elseif ($type === self::SHORT && is_int($item)) {
                $bytes .= $this->u16($item);
            } elseif ($type === self::LONG && is_int($item)) {
                $bytes .= $this->u32($item);
            }
        }

        return $bytes;
    }

    private function count(int $type, mixed $value): int
    {
        return match ($type) {
            self::ASCII => strlen(is_string($value) ? $value : '') + 1,
            self::BYTE, self::UNDEFINED => strlen(is_string($value) ? $value : ''),
            default => is_array($value) ? count($value) : 0,
        };
    }

    private function u16(int $value): string
    {
        return pack($this->littleEndian ? 'v' : 'n', $value);
    }

    private function u32(int $value): string
    {
        return pack($this->littleEndian ? 'V' : 'N', $value);
    }
}
