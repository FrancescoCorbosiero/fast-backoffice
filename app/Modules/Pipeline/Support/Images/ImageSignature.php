<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * What a file is, read from its first bytes: never from its name or from
 * what the browser claims. ISO media images (AVIF, HEIC, HEIF) are
 * recognised only so the client can be told to export a JPEG.
 */
final class ImageSignature
{
    /** The ISOBMFF brands of still and sequence images: AVIF, HEIF, HEIC. */
    private const array ISO_IMAGE_BRANDS = ['avif', 'avis', 'mif1', 'mif2', 'msf1', 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs'];

    public static function format(string $bytes): ?ImageFormat
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ImageFormat::Jpeg;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ImageFormat::Png;
        }
        if (strlen($bytes) >= 16 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return ImageFormat::Webp;
        }

        return null;
    }

    /**
     * An ISO base media file whose major or compatible brands name an
     * image format: AVIF, HEIC or HEIF.
     */
    public static function isIsoImage(string $bytes): bool
    {
        if (strlen($bytes) < 16 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }

        $size = unpack('N', $bytes, 0);
        $boxSize = is_array($size) && is_int($size[1] ?? null) ? min($size[1], strlen($bytes)) : 0;
        if ($boxSize < 16) {
            return false;
        }

        $brands = [substr($bytes, 8, 4)];
        for ($at = 16; $at + 4 <= $boxSize; $at += 4) {
            $brands[] = substr($bytes, $at, 4);
        }

        return array_intersect($brands, self::ISO_IMAGE_BRANDS) !== [];
    }
}
