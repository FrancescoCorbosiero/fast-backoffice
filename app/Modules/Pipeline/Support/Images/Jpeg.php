<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use App\Modules\Pipeline\Enums\ImageFormat;

/**
 * A JPEG without its metadata, losslessly: the segments that make the
 * picture (tables, frame, scans) are copied byte for byte and everything
 * else is dropped. Kept, rebuilt to their fixed size so nothing can ride
 * along: JFIF (without its thumbnail), the ICC profile, Adobe's colour
 * transform, and the orientation as a minimal EXIF. Dropped: the original
 * EXIF (GPS, camera, its thumbnail), XMP, IPTC, every other APPn (MPF,
 * JUMBF/C2PA), comments, and whatever follows the image's end (MPF
 * secondary images, gain maps, trailers).
 *
 * Only what browsers show: baseline, extended or progressive Huffman
 * frames at 8 bits per sample, one frame per file.
 */
final class Jpeg
{
    /**
     * @throws InvalidImage
     */
    public static function sanitize(string $bytes): SanitizedImage
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            throw InvalidImage::unreadable('not a JPEG stream');
        }

        $jfif = null;
        $orientation = null;
        $icc = '';
        $adobe = null;
        $body = '';
        $width = 0;
        $height = 0;
        $frames = 0;
        $scans = 0;
        $pos = 2;

        while (true) {
            if ($pos + 1 >= $length) {
                throw InvalidImage::unreadable('no end-of-image marker');
            }
            if ($bytes[$pos] !== "\xFF") {
                throw InvalidImage::unreadable('bytes between segments');
            }
            while ($pos + 1 < $length && $bytes[$pos + 1] === "\xFF") {
                $pos++;
            }
            if ($pos + 1 >= $length) {
                throw InvalidImage::unreadable('no end-of-image marker');
            }

            $start = $pos;
            $marker = ord($bytes[$pos + 1]);
            $pos += 2;

            if ($marker === 0xD9) {
                if ($scans === 0) {
                    throw InvalidImage::unreadable('no image data');
                }
                $body .= "\xFF\xD9";
                break;
            }
            if ($marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                throw InvalidImage::unreadable(sprintf('unexpected marker 0x%02X', $marker));
            }

            if ($pos + 2 > $length) {
                throw InvalidImage::unreadable('segment past the end');
            }
            $segmentLength = (ord($bytes[$pos]) << 8) | ord($bytes[$pos + 1]);
            if ($segmentLength < 2 || $pos + $segmentLength > $length) {
                throw InvalidImage::unreadable('segment past the end');
            }
            $end = $pos + $segmentLength;
            $payload = substr($bytes, $pos + 2, $segmentLength - 2);

            // Metadata: only what changes how the picture looks survives.
            if (($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE) {
                if ($marker === 0xE0 && $jfif === null && strlen($payload) >= 14 && str_starts_with($payload, "JFIF\0")) {
                    $jfif = substr($payload, 0, 12)."\0\0";
                } elseif ($marker === 0xE1 && $orientation === null && str_starts_with($payload, "Exif\0")) {
                    $orientation = ExifOrientation::read($payload);
                } elseif ($marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0")) {
                    $icc .= substr($bytes, $start, $end - $start);
                } elseif ($marker === 0xEE && $adobe === null && strlen($payload) >= 12 && str_starts_with($payload, 'Adobe')) {
                    $adobe = substr($payload, 0, 12);
                }
                $pos = $end;

                continue;
            }

            if ($marker === 0xC0 || $marker === 0xC1 || $marker === 0xC2) {
                if ($frames > 0) {
                    throw InvalidImage::unreadable('more than one frame');
                }
                if (strlen($payload) < 6 || ord($payload[0]) !== 8) {
                    throw InvalidImage::unreadable('not 8 bits per sample');
                }
                $height = (ord($payload[1]) << 8) | ord($payload[2]);
                $width = (ord($payload[3]) << 8) | ord($payload[4]);
                if ($width === 0 || $height === 0) {
                    throw InvalidImage::unreadable('no dimensions in the frame');
                }
                $frames++;
                $body .= substr($bytes, $start, $end - $start);
                $pos = $end;

                continue;
            }

            // Huffman tables, quantisation tables, the restart interval.
            if ($marker === 0xC4 || $marker === 0xDB || $marker === 0xDD) {
                $body .= substr($bytes, $start, $end - $start);
                $pos = $end;

                continue;
            }

            if ($marker === 0xDA) {
                if ($frames === 0) {
                    throw InvalidImage::unreadable('a scan before the frame');
                }
                $scanEnd = self::scanEnd($bytes, $end, $length);
                $body .= substr($bytes, $start, $scanEnd - $start);
                $scans++;
                $pos = $scanEnd;

                continue;
            }

            // Lossless, arithmetic or hierarchical frames, DNL, extensions.
            throw InvalidImage::unreadable(sprintf('unsupported marker 0x%02X', $marker));
        }

        $orientation ??= 1;
        $out = "\xFF\xD8";
        if ($jfif !== null) {
            $out .= self::segment(0xE0, $jfif);
        }
        $orientationAt = null;
        $orientationLength = 0;
        if (ExifOrientation::keeps($orientation)) {
            $exif = "Exif\0\0".ExifOrientation::minimalTiff($orientation);
            $orientationAt = strlen($out) + 4;
            $orientationLength = strlen($exif);
            $out .= self::segment(0xE1, $exif);
        }
        $out .= $icc;
        if ($adobe !== null) {
            $out .= self::segment(0xEE, $adobe);
        }
        $out .= $body;

        return SanitizedImage::oriented($out, ImageFormat::Jpeg, $width, $height, $orientation, $orientationAt, $orientationLength);
    }

    /**
     * Where a scan's entropy-coded data ends: the first 0xFF that is
     * neither a stuffed byte (FF 00) nor a restart marker (FF D0–D7).
     *
     * @throws InvalidImage
     */
    private static function scanEnd(string $bytes, int $from, int $length): int
    {
        $at = $from;
        while (true) {
            $ff = strpos($bytes, "\xFF", $at);
            if ($ff === false || $ff + 1 >= $length) {
                throw InvalidImage::unreadable('a scan without an end');
            }
            $next = ord($bytes[$ff + 1]);
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $at = $ff + 2;

                continue;
            }

            return $ff;
        }
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }
}
