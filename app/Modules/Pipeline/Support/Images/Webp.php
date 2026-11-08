<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * A WebP without its metadata, losslessly. The simple formats (one VP8
 * or VP8L chunk) have no room for any; the extended one (VP8X) keeps its
 * image, alpha, animation and ICC chunks byte for byte, on an allowlist,
 * also inside each animation frame, and drops EXIF, XMP, unknown chunks
 * and anything past the RIFF length. The orientation, when it isn't 1,
 * comes back as a minimal EXIF chunk after the image data, with its flag
 * set in VP8X.
 */
final class Webp
{
    private const int FLAG_ICC = 0x20;

    private const int FLAG_ALPHA = 0x10;

    private const int FLAG_EXIF = 0x08;

    private const int FLAG_ANIMATION = 0x02;

    private const array KEEP = ['ICCP', 'ANIM', 'ANMF', 'ALPH', 'VP8 ', 'VP8L'];

    private const array FRAME_KEEP = ['ALPH', 'VP8 ', 'VP8L'];

    /**
     * @throws InvalidImage
     */
    public static function sanitize(string $bytes): SanitizedImage
    {
        $length = strlen($bytes);
        if ($length < 20 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw InvalidImage::unreadable('not a WebP stream');
        }
        $riffSize = self::u32($bytes, 4);
        if ($riffSize < 12 || 8 + $riffSize > $length) {
            throw InvalidImage::unreadable('a truncated RIFF container');
        }

        $chunks = self::chunks($bytes, 12, 8 + $riffSize);
        [$first, $payload] = $chunks[0];

        if ($first === 'VP8 ' || $first === 'VP8L') {
            [$width, $height] = self::bitstreamSize($first, $payload);

            return SanitizedImage::oriented(self::riff(self::chunk($first, $payload)), ImageFormat::Webp, $width, $height, 1);
        }
        if ($first !== 'VP8X' || strlen($payload) < 10) {
            throw InvalidImage::unreadable('no VP8, VP8L or VP8X chunk first');
        }

        $flags = ord($payload[0]);
        $width = 1 + self::u24($payload, 4);
        $height = 1 + self::u24($payload, 7);
        $orientation = null;
        $body = '';
        $hasIcc = false;
        $hasImage = false;

        foreach (array_slice($chunks, 1) as [$fourcc, $data]) {
            if ($fourcc === 'EXIF') {
                $orientation ??= ExifOrientation::read($data);

                continue;
            }
            if (! in_array($fourcc, self::KEEP, true) || ($fourcc === 'ICCP' && $hasIcc)) {
                continue;
            }
            if ($fourcc === 'ICCP') {
                $hasIcc = true;
            }
            if ($fourcc === 'ANMF') {
                $data = self::frame($data);
            }
            $hasImage = $hasImage || in_array($fourcc, ['ANMF', 'VP8 ', 'VP8L'], true);
            $body .= self::chunk($fourcc, $data);
        }
        if (! $hasImage) {
            throw InvalidImage::unreadable('no image data');
        }

        $orientation ??= 1;
        $keepsOrientation = ExifOrientation::keeps($orientation);
        $flags &= self::FLAG_ALPHA | self::FLAG_ANIMATION;
        $flags |= ($hasIcc ? self::FLAG_ICC : 0) | ($keepsOrientation ? self::FLAG_EXIF : 0);

        $out = self::chunk('VP8X', chr($flags)."\0\0\0".substr($payload, 4, 6)).$body;
        $orientationAt = null;
        $orientationLength = 0;
        if ($keepsOrientation) {
            $tiff = ExifOrientation::minimalTiff($orientation);
            $orientationAt = 12 + strlen($out) + 8;
            $orientationLength = strlen($tiff);
            $out .= self::chunk('EXIF', $tiff);
        }

        return SanitizedImage::oriented(self::riff($out), ImageFormat::Webp, $width, $height, $orientation, $orientationAt, $orientationLength);
    }

    /**
     * An animation frame: its 16-byte header, then only its image chunks.
     *
     * @throws InvalidImage
     */
    private static function frame(string $payload): string
    {
        if (strlen($payload) < 16 + 8) {
            throw InvalidImage::unreadable('a broken animation frame');
        }

        $frame = substr($payload, 0, 16);
        $hasImage = false;
        foreach (self::chunks($payload, 16, strlen($payload)) as [$fourcc, $data]) {
            if (in_array($fourcc, self::FRAME_KEEP, true)) {
                $frame .= self::chunk($fourcc, $data);
                $hasImage = $hasImage || $fourcc !== 'ALPH';
            }
        }
        if (! $hasImage) {
            throw InvalidImage::unreadable('an animation frame without an image');
        }

        return $frame;
    }

    /**
     * @return non-empty-list<array{0: string, 1: string}>
     *
     * @throws InvalidImage
     */
    private static function chunks(string $bytes, int $from, int $end): array
    {
        $chunks = [];
        $pos = $from;
        while ($pos < $end) {
            if ($pos + 8 > $end) {
                throw InvalidImage::unreadable('a broken chunk');
            }
            $size = self::u32($bytes, $pos + 4);
            if ($size > $end - $pos - 8) {
                throw InvalidImage::unreadable('a chunk past the end');
            }
            $chunks[] = [substr($bytes, $pos, 4), substr($bytes, $pos + 8, $size)];
            $pos += 8 + $size + ($size % 2);
        }
        if ($chunks === []) {
            throw InvalidImage::unreadable('no chunks');
        }

        return $chunks;
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws InvalidImage
     */
    private static function bitstreamSize(string $fourcc, string $payload): array
    {
        if ($fourcc === 'VP8 ') {
            if (strlen($payload) < 10 || substr($payload, 3, 3) !== "\x9D\x01\x2A") {
                throw InvalidImage::unreadable('a broken VP8 frame');
            }
            $size = [self::u16($payload, 6) & 0x3FFF, self::u16($payload, 8) & 0x3FFF];
        } else {
            if (strlen($payload) < 5 || $payload[0] !== "\x2F") {
                throw InvalidImage::unreadable('a broken VP8L stream');
            }
            $bits = self::u32($payload, 1);
            $size = [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1];
        }
        if ($size[0] === 0 || $size[1] === 0) {
            throw InvalidImage::unreadable('no dimensions');
        }

        return $size;
    }

    private static function riff(string $chunks): string
    {
        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    private static function chunk(string $fourcc, string $data): string
    {
        $size = strlen($data);

        return $fourcc.pack('V', $size).$data.($size % 2 === 1 ? "\0" : '');
    }

    private static function u16(string $bytes, int $at): int
    {
        $value = unpack('v', $bytes, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function u24(string $bytes, int $at): int
    {
        return ord($bytes[$at]) | (ord($bytes[$at + 1]) << 8) | (ord($bytes[$at + 2]) << 16);
    }

    private static function u32(string $bytes, int $at): int
    {
        $value = unpack('V', $bytes, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
