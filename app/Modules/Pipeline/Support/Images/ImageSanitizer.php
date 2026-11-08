<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * The one way an attachment's bytes are cleaned (docs/pipeline.md §10):
 * the type read from the content, the dimensions from the container, the
 * metadata stripped at the byte level (no pixel is decoded or re-encoded,
 * ever), the orientation kept as a minimal EXIF. Two nets behind the
 * format code: the result must come out of a second pass unchanged, and
 * a scan of its bytes must find no metadata but the one block written.
 */
final class ImageSanitizer
{
    /** Markers of metadata blocks; long enough not to occur by chance in compressed data. */
    private const array MARKERS = [
        "Exif\0\0",
        "MM\0*\0\0\0\x08",
        "II*\0\x08\0\0\0",
        '<x:xmpmeta',
        '<?xpacket',
        'http://ns.adobe.com/xap/1.0/',
        'http://ns.adobe.com/xmp/extension/',
        "Photoshop 3.0\0",
    ];

    /**
     * @param  list<ImageFormat>  $formats  the types accepted
     *
     * @throws InvalidImage
     */
    public function sanitize(string $bytes, array $formats, int $maxMegapixels): SanitizedImage
    {
        $format = ImageSignature::format($bytes);
        if ($format === null || ! in_array($format, $formats, true)) {
            throw ImageSignature::isIsoImage($bytes) ? InvalidImage::isoMedia() : InvalidImage::notAnImage();
        }

        $image = self::pass($format, $bytes);
        if ($image->width * $image->height > $maxMegapixels * 1_000_000) {
            throw InvalidImage::tooManyPixels($maxMegapixels);
        }

        $again = self::pass($format, $image->bytes);
        if ($again->bytes !== $image->bytes || $again->orientation !== $image->orientation) {
            throw InvalidImage::notCleaned('a second pass changed the file');
        }

        $rest = $image->withoutOrientationBlock();
        foreach (self::MARKERS as $marker) {
            if (str_contains($rest, $marker)) {
                throw InvalidImage::notCleaned('a metadata marker is left: '.bin2hex($marker));
            }
        }

        return $image;
    }

    /**
     * @throws InvalidImage
     */
    private static function pass(ImageFormat $format, string $bytes): SanitizedImage
    {
        return match ($format) {
            ImageFormat::Jpeg => Jpeg::sanitize($bytes),
            ImageFormat::Png => Png::sanitize($bytes),
            ImageFormat::Webp => Webp::sanitize($bytes),
        };
    }
}
