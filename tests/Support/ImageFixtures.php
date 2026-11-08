<?php

declare(strict_types=1);

namespace Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Images built in code for the sanitizer's tests: no binary fixtures in
 * the repository, no real photo, no real place. The pixels are four
 * coloured quadrants on a non-square canvas (48×32); the metadata is
 * hand-built and every block carries a string starting with "CANARY",
 * so "nothing survived" is one search. The GPS coordinates are invented.
 */
final class ImageFixtures
{
    public const string CANARY = 'CANARY';

    public const int WIDTH = 48;

    public const int HEIGHT = 32;

    public const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /**
     * A JPEG from GD with everything a phone or an editor may add: JFIF
     * with a thumbnail, JFXX, EXIF (camera, date, GPS, IFD1 thumbnail),
     * XMP, extended XMP, the ICC profile, MPF, JUMBF, IPTC, an Adobe
     * segment padded with extra bytes, a comment, and after the image's
     * end a second JPEG with its own GPS.
     */
    public static function jpeg(int $orientation = 6, bool $littleEndian = true, bool $orientationAsLong = false, bool $progressive = false): string
    {
        $base = self::gdJpeg($progressive);
        $tiff = self::exif($orientation, $littleEndian, $orientationAsLong);

        $head = self::segment(0xE0, "JFIF\0\x01\x02\x00\x00\x01\x00\x01\x02\x02".'CANARY-JFIF!')
            .self::segment(0xE0, "JFXX\0\x10\xFF\xD8CANARY-JFXX\xFF\xD9")
            .self::segment(0xE1, "Exif\0\0".$tiff)
            .self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".self::xmp())
            .self::segment(0xE1, "http://ns.adobe.com/xmp/extension/\0".str_repeat('A', 32).pack('NN', 16, 0).'CANARY-XMP-EXT')
            .self::segment(0xE2, "ICC_PROFILE\0\x01\x01".self::icc())
            .self::segment(0xE2, "MPF\0II*\0\x08\0\0\0CANARY-MPF")
            .self::segment(0xEB, "JP\0\0CANARY-JUMBF")
            .self::segment(0xED, "Photoshop 3.0\0".self::iptc())
            .self::segment(0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x01".'CANARY-ADOBE')
            .self::segment(0xFE, 'CANARY-COMMENT');

        if ($progressive) {
            // Metadata may sit between the scans of a progressive file too.
            $second = strpos($base, "\xFF\xDA", (int) strpos($base, "\xFF\xDA") + 2);
            if ($second === false) {
                throw new RuntimeException('GD wrote a progressive JPEG with a single scan.');
            }
            $base = substr($base, 0, $second).self::segment(0xE1, "Exif\0\0".self::exif(3, true, false)).self::segment(0xFE, 'CANARY-BETWEEN-SCANS').substr($base, $second);
        }

        $trailer = "\xFF\xD8".self::segment(0xE1, "Exif\0\0".self::exif(1, false, false, 'CANARY-TRAILER'))."\xFF\xD9";

        return "\xFF\xD8".$head.substr($base, 2).$trailer;
    }

    /**
     * GD's JPEG of the quadrants, untouched: the pixel data a clean file
     * must still hold byte for byte.
     */
    public static function gdJpeg(bool $progressive = false): string
    {
        self::requireGd('imagejpeg');
        $image = self::quadrants();
        imageinterlace($image, $progressive);
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * The entropy-coded part of a JPEG, from the first scan to EOI.
     */
    public static function jpegScans(string $jpeg): string
    {
        $sos = strpos($jpeg, "\xFF\xDA");
        $eoi = strrpos($jpeg, "\xFF\xD9");
        if ($sos === false || $eoi === false) {
            throw new RuntimeException('Not a JPEG with scans.');
        }

        return substr($jpeg, $sos, $eoi + 2 - $sos);
    }

    /**
     * A PNG from GD with eXIf (orientation and GPS), text chunks (XMP,
     * "Raw profile" EXIF), a timestamp, the ICC profile, a preview, Apple's
     * and C2PA's chunks, and data after IEND.
     *
     * @param  list<string>  $extraChunks  raw chunks inserted after IHDR
     */
    public static function png(int $orientation = 6, array $extraChunks = []): string
    {
        $chunks = self::pngChunks(self::gdPng());
        $ihdr = array_shift($chunks);

        $inserted = [
            self::pngChunk('iCCP', "icc\0\0".(string) gzcompress(self::icc())),
            self::pngChunk('eXIf', self::exif($orientation, true, false)),
            self::pngChunk('tEXt', "Comment\0CANARY-TEXT"),
            self::pngChunk('zTXt', "Raw profile type exif\0\0".(string) gzcompress("exif\n  CANARY-ZTXT\n")),
            self::pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".self::xmp()),
            self::pngChunk('tIME', "\x07\xEA\x03\x0F\x0E\x17\x0C"),
            self::pngChunk('prVW', 'CANARY-PREVIEW'),
            self::pngChunk('iDOT', "\0\0\0\x02CANARY-IDOT"),
            self::pngChunk('caBX', 'CANARY-C2PA'),
            ...$extraChunks,
        ];

        return self::PNG_SIGNATURE.$ihdr.implode('', $inserted).implode('', $chunks).'CANARY-AFTER-IEND';
    }

    public static function gdPng(): string
    {
        self::requireGd('imagepng');
        ob_start();
        imagepng(self::quadrants(), null, 9);

        return (string) ob_get_clean();
    }

    /**
     * The IDAT chunks of a PNG, concatenated as they are in the file.
     */
    public static function pngIdat(string $png): string
    {
        return implode('', array_filter(self::pngChunks($png), fn (string $chunk): bool => substr($chunk, 4, 4) === 'IDAT'));
    }

    /**
     * The chunks of a PNG, each with its length, type, data and CRC.
     *
     * @return list<string>
     */
    public static function pngChunks(string $png): array
    {
        $chunks = [];
        $pos = 8;
        while ($pos + 12 <= strlen($png)) {
            $length = self::u32($png, $pos, 'N');
            $type = substr($png, $pos + 4, 4);
            $chunks[] = substr($png, $pos, $length + 12);
            $pos += $length + 12;
            if ($type === 'IEND') {
                break;
            }
        }

        return $chunks;
    }

    public static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /**
     * A PNG that claims a size its few bytes don't decode to: the
     * megapixel cap has to come from the header, never from decoding.
     */
    public static function hugePng(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return self::PNG_SIGNATURE.self::pngChunk('IHDR', $ihdr).self::pngChunk('IDAT', (string) gzcompress('x')).self::pngChunk('IEND', '');
    }

    /**
     * A PNG whose IDAT is a stored (uncompressed) deflate stream holding
     * the given bytes as they are: what the final marker scan exists for.
     */
    public static function pngWithPlantedBytes(string $planted): string
    {
        $raw = "\x00".$planted;
        $deflate = "\x01".pack('v', strlen($raw)).pack('v', ~strlen($raw) & 0xFFFF).$raw;
        $zlib = "\x78\x01".$deflate.pack('N', (int) hexdec(hash('adler32', $raw)));
        $ihdr = pack('NN', strlen($planted), 1)."\x08\x00\x00\x00\x00";

        return self::PNG_SIGNATURE.self::pngChunk('IHDR', $ihdr).self::pngChunk('IDAT', $zlib).self::pngChunk('IEND', '');
    }

    /**
     * An extended WebP from GD's bitstream with ICCP, EXIF (orientation and
     * GPS), XMP, an unknown chunk and data after the RIFF length; animated,
     * it carries two frames, each with an unknown chunk inside.
     */
    public static function webp(int $orientation = 6, bool $lossless = false, bool $animated = false): string
    {
        [$fourcc, $bitstream] = self::webpBitstream($lossless);
        $flags = 0x20 | 0x08 | 0x04 | ($animated ? 0x02 : 0) | 0xC1;
        $vp8x = chr($flags)."\0\0\0".self::u24(self::WIDTH - 1).self::u24(self::HEIGHT - 1);

        $image = self::riffChunk($fourcc, $bitstream);
        if ($animated) {
            $frame = self::u24(0).self::u24(0).self::u24(self::WIDTH - 1).self::u24(self::HEIGHT - 1).self::u24(100)."\x00";
            $frame .= self::riffChunk($fourcc, $bitstream).self::riffChunk('CANF', 'CANARY-FRAME');
            $image = self::riffChunk('ANIM', "\0\0\0\0\0\0").self::riffChunk('ANMF', $frame).self::riffChunk('ANMF', $frame);
        }

        $chunks = self::riffChunk('VP8X', $vp8x)
            .self::riffChunk('ICCP', self::icc())
            .$image
            .self::riffChunk('EXIF', self::exif($orientation, true, false))
            .self::riffChunk('XMP ', self::xmp())
            .self::riffChunk('CANA', 'CANARY-UNKNOWN');

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks.'CANARY-AFTER-RIFF';
    }

    /**
     * GD's own WebP: the simple format, a single VP8 or VP8L chunk.
     */
    public static function gdWebp(bool $lossless = false): string
    {
        self::requireGd('imagewebp');
        ob_start();
        imagewebp(self::quadrants(), null, $lossless ? IMG_WEBP_LOSSLESS : 90);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: string, 1: string} the fourcc and payload of GD's bitstream chunk
     */
    public static function webpBitstream(bool $lossless = false): array
    {
        $webp = self::gdWebp($lossless);
        $size = self::u32($webp, 16, 'V');

        return [substr($webp, 12, 4), substr($webp, 20, $size)];
    }

    public static function riffChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
    }

    /**
     * A TIFF/EXIF structure as a camera writes it: IFD0 with make, model and
     * orientation, the Exif sub-IFD with the capture date and a comment,
     * the GPS sub-IFD with invented coordinates, and IFD1 with a thumbnail.
     */
    public static function exif(int $orientation, bool $littleEndian = true, bool $orientationAsLong = false, string $canary = 'CANARY-GPS'): string
    {
        $tiff = new TiffWriter($littleEndian);

        return $tiff->build(
            ifd0: [
                [0x010F, TiffWriter::ASCII, 'CANARY-MAKE'],
                [0x0110, TiffWriter::ASCII, 'CANARY-MODEL'],
                [0x0112, $orientationAsLong ? TiffWriter::LONG : TiffWriter::SHORT, [$orientation]],
                [0x0131, TiffWriter::ASCII, 'CANARY-SOFTWARE'],
            ],
            exif: [
                [0x9003, TiffWriter::ASCII, '2026:03:15 14:23:12'],
                [0x9286, TiffWriter::UNDEFINED, "ASCII\0\0\0CANARY-USER-COMMENT"],
            ],
            gps: [
                [0x0000, TiffWriter::BYTE, "\x02\x03\x00\x00"],
                [0x0001, TiffWriter::ASCII, 'N'],
                [0x0002, TiffWriter::RATIONAL, [[45, 1], [12, 1], [3456, 100]]],
                [0x0003, TiffWriter::ASCII, 'E'],
                [0x0004, TiffWriter::RATIONAL, [[9, 1], [8, 1], [765, 100]]],
                [0x001C, TiffWriter::UNDEFINED, "ASCII\0\0\0".$canary],
            ],
            thumbnail: "\xFF\xD8CANARY-THUMBNAIL\xFF\xD9",
        );
    }

    public static function xmp(): string
    {
        return '<?xpacket begin="'."\u{FEFF}".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"'
            .' exif:GPSLatitude="45,12.5760N" exif:GPSLongitude="9,8.1275E" photoshop:City="CANARY-XMP-CITY"/>'
            .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /**
     * Photoshop's image resource block with IPTC (record 2: city).
     */
    public static function iptc(): string
    {
        $city = 'CANARY-IPTC-CITY';
        $iptc = "\x1C\x02\x5A".pack('n', strlen($city)).$city;
        $iptc .= strlen($iptc) % 2 === 1 ? "\0" : '';

        return '8BIM'.pack('n', 0x0404)."\0\0".pack('N', strlen($iptc)).$iptc;
    }

    /**
     * A small well-formed ICC profile: a header that says RGB display and
     * one description tag (libpng warns about a profile with none). The
     * sanitizer must keep it byte for byte.
     */
    public static function icc(): string
    {
        $description = 'desc'."\0\0\0\0".pack('N', 5)."test\0".str_repeat("\0", 78);
        $size = 128 + 4 + 12 + strlen($description);
        $header = pack('N', $size).'test'.pack('N', 0x02100000).'mntr'.'RGB '.'XYZ '
            .pack('nnnnnn', 2026, 3, 15, 14, 23, 12).'acsp'.'APPL'.pack('N', 0).'none'.'none'
            .str_repeat("\0", 8).pack('N', 0)
            .pack('NNN', 0x0000F6D6, 0x00010000, 0x0000D32D)
            .'test'.str_repeat("\0", 16).str_repeat("\0", 28);

        return $header.pack('N', 1).'desc'.pack('NN', 144, strlen($description)).$description;
    }

    /**
     * The four quadrants: red, green / blue, yellow.
     */
    public static function quadrants(): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        $halfWidth = intdiv(self::WIDTH, 2);
        $halfHeight = intdiv(self::HEIGHT, 2);
        foreach ([[0, 0, [255, 0, 0]], [1, 0, [0, 255, 0]], [0, 1, [0, 0, 255]], [1, 1, [255, 255, 0]]] as [$x, $y, $rgb]) {
            $colour = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            imagefilledrectangle($image, $x * $halfWidth, $y * $halfHeight, $x * $halfWidth + $halfWidth - 1, $y * $halfHeight + $halfHeight - 1, (int) $colour);
        }

        return $image;
    }

    public static function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    /**
     * @param  'N'|'V'  $format  big- or little-endian
     */
    private static function u32(string $bytes, int $at, string $format): int
    {
        $value = unpack($format, $bytes, $at);
        if (! is_array($value) || ! is_int($value[1] ?? null)) {
            throw new RuntimeException('Not enough bytes for a 32-bit value.');
        }

        return $value[1];
    }

    private static function u24(int $value): string
    {
        return chr($value & 0xFF).chr(($value >> 8) & 0xFF).chr(($value >> 16) & 0xFF);
    }

    /**
     * The fixtures need GD with JPEG, PNG and WebP: without them the tests
     * fail rather than skip, so a CI image missing one shows up.
     */
    private static function requireGd(string $function): void
    {
        if (! function_exists($function)) {
            throw new RuntimeException("GD without {$function}(): the image tests need GD with JPEG, PNG and WebP.");
        }
    }
}
