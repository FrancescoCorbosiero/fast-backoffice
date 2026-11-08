<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * The image types a change request accepts (docs/pipeline.md §10), backed
 * by their MIME type. The type is read from the file's bytes, never from
 * its name; the stored file gets the extension of the type it really is.
 */
enum ImageFormat: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Jpeg => 'JPEG',
            self::Png => 'PNG',
            self::Webp => 'WebP',
        };
    }
}
