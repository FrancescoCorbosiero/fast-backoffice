<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

/**
 * The one EXIF fact a stripped image keeps: its orientation (tag 0x0112
 * of IFD0). Read from whatever EXIF the file carried; written back as the
 * smallest TIFF structure that holds it, so browsers and the site's build
 * still turn the picture the right way and nothing else survives.
 */
final class ExifOrientation
{
    private const int TAG = 0x0112;

    /**
     * The orientation in a TIFF structure (an EXIF block), with or without
     * the "Exif\0\0" prefix JPEG puts before it; 1 when absent or invalid.
     */
    public static function read(string $exif): int
    {
        if (str_starts_with($exif, "Exif\0")) {
            $exif = substr($exif, 6);
        }
        $length = strlen($exif);
        if ($length < 8) {
            return 1;
        }

        $order = substr($exif, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $little = $order === 'II';
        if (self::u16($exif, 2, $little) !== 42) {
            return 1;
        }

        $ifd = self::u32($exif, 4, $little);
        if ($ifd < 8 || $ifd + 2 > $length) {
            return 1;
        }

        $count = self::u16($exif, $ifd, $little);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $length) {
                return 1;
            }
            if (self::u16($exif, $entry, $little) !== self::TAG) {
                continue;
            }

            // A SHORT sits in the first two bytes of the value field; some
            // writers use a LONG.
            $value = match (self::u16($exif, $entry + 2, $little)) {
                3 => self::u16($exif, $entry + 8, $little),
                4 => self::u32($exif, $entry + 8, $little),
                default => 1,
            };

            return $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }

    /**
     * The smallest EXIF that keeps an orientation, 26 bytes: a big-endian
     * TIFF header, IFD0 with the single entry 0x0112 (SHORT, count 1), no
     * next IFD.
     */
    public static function minimalTiff(int $orientation): string
    {
        return "MM\x00\x2A".pack('N', 8)
            .pack('n', 1)
            .pack('nnN', self::TAG, 3, 1).pack('n', $orientation)."\x00\x00"
            .pack('N', 0);
    }

    /**
     * Whether an orientation needs keeping: 1 is the default, so no EXIF.
     */
    public static function keeps(int $orientation): bool
    {
        return $orientation >= 2 && $orientation <= 8;
    }

    private static function u16(string $bytes, int $at, bool $little): int
    {
        $value = unpack($little ? 'v' : 'n', $bytes, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function u32(string $bytes, int $at, bool $little): int
    {
        $value = unpack($little ? 'V' : 'N', $bytes, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
