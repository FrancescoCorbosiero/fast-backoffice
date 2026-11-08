<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * An image as it will be stored: the same pixel data, no metadata but the
 * orientation (when it isn't 1), and its dimensions as displayed (swapped
 * for orientations 5–8, which turn the picture a quarter).
 */
final readonly class SanitizedImage
{
    /**
     * @param  ?int  $orientationAt  where the minimal orientation EXIF starts in $bytes, null when there is none
     * @param  int  $orientationLength  its length in bytes
     */
    public function __construct(
        public string $bytes,
        public ImageFormat $format,
        public int $width,
        public int $height,
        public int $orientation,
        public ?int $orientationAt = null,
        public int $orientationLength = 0,
    ) {}

    /**
     * @param  int  $pixelWidth  as stored, before the orientation turns it
     */
    public static function oriented(string $bytes, ImageFormat $format, int $pixelWidth, int $pixelHeight, int $orientation, ?int $orientationAt = null, int $orientationLength = 0): self
    {
        $turned = $orientation >= 5;

        return new self($bytes, $format, $turned ? $pixelHeight : $pixelWidth, $turned ? $pixelWidth : $pixelHeight, $orientation, $orientationAt, $orientationLength);
    }

    /**
     * The bytes without the one EXIF block the sanitizer wrote: what the
     * final scan for leftover metadata reads.
     */
    public function withoutOrientationBlock(): string
    {
        if ($this->orientationAt === null) {
            return $this->bytes;
        }

        return substr($this->bytes, 0, $this->orientationAt).substr($this->bytes, $this->orientationAt + $this->orientationLength);
    }
}
