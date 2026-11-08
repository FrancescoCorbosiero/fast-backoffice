<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * A PNG without its metadata, losslessly: the chunks that make the
 * picture and its colours are copied byte for byte, on an allowlist;
 * everything else is dropped (eXIf, text chunks with XMP or "Raw
 * profile" EXIF, timestamps, previews, private chunks), and so is
 * whatever follows IEND, where a cropping tool may have left the
 * uncropped original. The orientation, when it isn't 1, comes back as a
 * minimal eXIf before the first IDAT.
 */
final class Png
{
    private const string SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** Chunks that make the picture, its colours or its animation (APNG). */
    private const array KEEP = [
        'IHDR', 'PLTE', 'IDAT', 'IEND',
        'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'cICP', 'mDCV', 'cLLI', 'bKGD', 'pHYs', 'sPLT', 'hIST',
        'acTL', 'fcTL', 'fdAT',
    ];

    /**
     * @throws InvalidImage
     */
    public static function sanitize(string $bytes): SanitizedImage
    {
        $length = strlen($bytes);
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw InvalidImage::unreadable('not a PNG stream');
        }

        /** @var list<array{type: string, bytes: string}> $kept */
        $kept = [];
        $orientation = null;
        $width = 0;
        $height = 0;
        $pos = 8;
        $ended = false;

        while (! $ended) {
            if ($pos + 12 > $length) {
                throw InvalidImage::unreadable('no IEND chunk');
            }
            $chunkLength = self::u32($bytes, $pos);
            $type = substr($bytes, $pos + 4, 4);
            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1 || $chunkLength > $length - $pos - 12) {
                throw InvalidImage::unreadable('a broken chunk');
            }
            $data = substr($bytes, $pos + 8, $chunkLength);
            $chunk = substr($bytes, $pos, $chunkLength + 12);
            $pos += $chunkLength + 12;

            if ($kept === [] && $type !== 'IHDR') {
                throw InvalidImage::unreadable('IHDR is not the first chunk');
            }

            if ($type === 'eXIf') {
                $orientation ??= ExifOrientation::read($data);

                continue;
            }

            if (! in_array($type, self::KEEP, true)) {
                if ((ord($type[0]) & 0x20) === 0) {
                    throw InvalidImage::unreadable("an unknown critical chunk {$type}");
                }

                continue;
            }

            if (self::u32($chunk, $chunkLength + 8) !== crc32($type.$data)) {
                throw InvalidImage::unreadable("a bad checksum on {$type}");
            }

            if ($type === 'IHDR') {
                if ($chunkLength !== 13) {
                    throw InvalidImage::unreadable('a broken IHDR');
                }
                $width = self::u32($data, 0);
                $height = self::u32($data, 4);
                if ($width === 0 || $height === 0) {
                    throw InvalidImage::unreadable('no dimensions');
                }
            }

            $kept[] = ['type' => $type, 'bytes' => $chunk];
            $ended = $type === 'IEND';
        }

        $orientation ??= 1;
        $out = self::SIGNATURE;
        $orientationAt = null;
        $orientationLength = 0;
        foreach ($kept as $chunk) {
            if ($chunk['type'] === 'IDAT' && $orientationAt === null && ExifOrientation::keeps($orientation)) {
                $tiff = ExifOrientation::minimalTiff($orientation);
                $orientationAt = strlen($out) + 8;
                $orientationLength = strlen($tiff);
                $out .= self::chunk('eXIf', $tiff);
            }
            $out .= $chunk['bytes'];
        }

        if (! in_array('IDAT', array_column($kept, 'type'), true)) {
            throw InvalidImage::unreadable('no image data');
        }

        return SanitizedImage::oriented($out, ImageFormat::Png, $width, $height, $orientation, $orientationAt, $orientationLength);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function u32(string $bytes, int $at): int
    {
        $value = unpack('N', $bytes, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
