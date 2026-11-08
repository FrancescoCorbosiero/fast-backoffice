<?php

declare(strict_types=1);

use App\Modules\Pipeline\Enums\ImageFormat;
use App\Modules\Pipeline\Support\Images\ExifOrientation;
use App\Modules\Pipeline\Support\Images\ImageSanitizer;
use App\Modules\Pipeline\Support\Images\InvalidImage;
use App\Modules\Pipeline\Support\Images\SanitizedImage;
use Tests\Support\ImageFixtures;

function sanitizeImage(string $bytes, int $megapixels = 50): SanitizedImage
{
    return (new ImageSanitizer)->sanitize($bytes, [ImageFormat::Jpeg, ImageFormat::Png, ImageFormat::Webp], $megapixels);
}

/**
 * The APPn and COM segments of a JPEG before its first scan, as [marker, payload].
 *
 * @return list<array{0: int, 1: string}>
 */
function jpegSegments(string $jpeg): array
{
    $segments = [];
    $pos = 2;
    while ($pos + 4 <= strlen($jpeg) && $jpeg[$pos] === "\xFF") {
        $marker = ord($jpeg[$pos + 1]);
        if ($marker === 0xDA) {
            break;
        }
        $length = (int) unpack('n', $jpeg, $pos + 2)[1];
        if (($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE) {
            $segments[] = [$marker, substr($jpeg, $pos + 4, $length - 2)];
        }
        $pos += 2 + $length;
    }

    return $segments;
}

/**
 * @return list<string> the fourccs of a WebP's chunks
 */
function webpChunkTypes(string $webp): array
{
    $types = [];
    $pos = 12;
    while ($pos + 8 <= strlen($webp)) {
        $size = (int) unpack('V', $webp, $pos + 4)[1];
        $types[] = substr($webp, $pos, 4);
        $pos += 8 + $size + ($size % 2);
    }

    return $types;
}

function webpChunk(string $webp, string $fourcc): ?string
{
    $pos = 12;
    while ($pos + 8 <= strlen($webp)) {
        $size = (int) unpack('V', $webp, $pos + 4)[1];
        if (substr($webp, $pos, 4) === $fourcc) {
            return substr($webp, $pos + 8, $size);
        }
        $pos += 8 + $size + ($size % 2);
    }

    return null;
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function rgbAt(string $bytes, int $x, int $y): array
{
    $image = imagecreatefromstring($bytes);
    expect($image)->not->toBeFalse();
    $colour = imagecolorat($image, $x, $y);

    return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
}

describe('JPEG', function (): void {
    it('drops every metadata block and whatever follows the image, losslessly', function (): void {
        $base = ImageFixtures::gdJpeg();
        $image = sanitizeImage(ImageFixtures::jpeg(orientation: 6));

        expect($image->format)->toBe(ImageFormat::Jpeg)
            ->and($image->bytes)->not->toContain(ImageFixtures::CANARY)
            ->and($image->bytes)->not->toContain('http://ns.adobe.com')->not->toContain('Photoshop 3.0')->not->toContain('MPF')->not->toContain('CREATOR')
            // The pixel data is the same bytes, and the file ends at the image's end.
            ->and(ImageFixtures::jpegScans($image->bytes))->toBe(ImageFixtures::jpegScans($base))
            ->and($image->bytes)->toEndWith(ImageFixtures::jpegScans($base))
            ->and(rgbAt($image->bytes, 5, 5))->toBe(rgbAt($base, 5, 5))
            ->and(rgbAt($image->bytes, 40, 28))->toBe(rgbAt($base, 40, 28));

        // What is left: JFIF without its thumbnail, the orientation, the ICC profile, Adobe's 12 bytes.
        $segments = jpegSegments($image->bytes);
        expect(array_map(fn (array $segment): int => $segment[0], $segments))->toBe([0xE0, 0xE1, 0xE2, 0xEE])
            ->and($segments[0][1])->toBe("JFIF\0\x01\x02\x00\x00\x01\x00\x01\x00\x00")
            ->and($segments[1][1])->toBe("Exif\0\0".ExifOrientation::minimalTiff(6))
            ->and($segments[2][1])->toBe("ICC_PROFILE\0\x01\x01".ImageFixtures::icc())
            ->and($segments[3][1])->toBe("Adobe\x00\x64\x00\x00\x00\x00\x01");

        // PHP's own parser agrees: APP1 holds the orientation only.
        getimagesizefromstring($image->bytes, $info);
        expect(array_keys($info))->toBe(['APP0', 'APP1', 'APP2', 'APP14']);
    });

    it('drops metadata between the scans of a progressive file', function (): void {
        $image = sanitizeImage(ImageFixtures::jpeg(orientation: 6, progressive: true));

        expect($image->bytes)->not->toContain(ImageFixtures::CANARY)
            ->and(substr_count($image->bytes, "Exif\0\0"))->toBe(1)
            ->and(ImageFixtures::jpegScans($image->bytes))->toBe(ImageFixtures::jpegScans(ImageFixtures::gdJpeg(progressive: true)));
    });

    it('reads the orientation in either byte order, as a SHORT or a LONG', function (bool $littleEndian, bool $long): void {
        $image = sanitizeImage(ImageFixtures::jpeg(orientation: 8, littleEndian: $littleEndian, orientationAsLong: $long));

        expect($image->orientation)->toBe(8)
            ->and(jpegSegments($image->bytes)[1][1])->toBe("Exif\0\0".ExifOrientation::minimalTiff(8));
    })->with([
        'II, SHORT' => [true, false],
        'MM, SHORT' => [false, false],
        'II, LONG' => [true, true],
        'MM, LONG' => [false, true],
    ]);

    it('refuses what browsers don\'t show and files that don\'t end', function (string $bytes): void {
        expect(fn () => sanitizeImage($bytes))->toThrow(InvalidImage::class);
    })->with([
        'truncated in a scan' => fn (): string => substr(ImageFixtures::gdJpeg(), 0, -40),
        'no end marker' => fn (): string => substr(ImageFixtures::gdJpeg(), 0, -2),
        'arithmetic coding' => fn (): string => str_replace("\xFF\xC0", "\xFF\xC9", ImageFixtures::gdJpeg()),
        'bytes between segments' => fn (): string => "\xFF\xD8junk".substr(ImageFixtures::gdJpeg(), 2),
    ]);
});

describe('PNG', function (): void {
    it('keeps only the chunks of the picture and its colours, and cuts what follows IEND', function (): void {
        $base = ImageFixtures::gdPng();
        $image = sanitizeImage(ImageFixtures::png(orientation: 6));
        $types = array_map(fn (string $chunk): string => substr($chunk, 4, 4), ImageFixtures::pngChunks($image->bytes));
        // GD's own ancillary chunks (pHYs, the pixel density, for libgd 2.3) are on the allowlist.
        $gd = array_values(array_diff(array_map(fn (string $chunk): string => substr($chunk, 4, 4), ImageFixtures::pngChunks($base)), ['IHDR', 'IDAT', 'IEND']));

        expect($image->format)->toBe(ImageFormat::Png)
            ->and($image->bytes)->not->toContain(ImageFixtures::CANARY)
            ->and(array_values(array_unique($types)))->toBe(['IHDR', 'iCCP', ...$gd, 'eXIf', 'IDAT', 'IEND'])
            ->and(ImageFixtures::pngIdat($image->bytes))->toBe(ImageFixtures::pngIdat($base))
            ->and($image->bytes)->toEndWith(ImageFixtures::pngChunk('IEND', ''))
            ->and($image->bytes)->toContain(ImageFixtures::pngChunk('iCCP', "icc\0\0".gzcompress(ImageFixtures::icc())))
            ->and($image->bytes)->toContain(ImageFixtures::pngChunk('eXIf', ExifOrientation::minimalTiff(6)))
            ->and(rgbAt($image->bytes, 40, 28))->toBe([255, 255, 0]);
    });

    it('refuses an unknown critical chunk, a bad checksum and a missing IEND', function (string $bytes): void {
        expect(fn () => sanitizeImage($bytes))->toThrow(InvalidImage::class);
    })->with([
        'unknown critical chunk' => fn (): string => ImageFixtures::png(1, [ImageFixtures::pngChunk('ABCD', 'x')]),
        'bad checksum' => fn (): string => substr_replace(ImageFixtures::gdPng(), "\0\0\0\0", 29, 4),
        'no IEND' => fn (): string => substr(ImageFixtures::gdPng(), 0, -12),
    ]);
});

describe('WebP', function (): void {
    it('keeps the image, its alpha, animation and ICC chunks, and drops the rest, also inside frames', function (bool $lossless, bool $animated): void {
        [$fourcc, $bitstream] = ImageFixtures::webpBitstream($lossless);
        $image = sanitizeImage(ImageFixtures::webp(orientation: 6, lossless: $lossless, animated: $animated));
        $flags = ord((string) webpChunk($image->bytes, 'VP8X'));

        expect($image->format)->toBe(ImageFormat::Webp)
            ->and($image->bytes)->not->toContain(ImageFixtures::CANARY)
            ->and(webpChunkTypes($image->bytes))->toBe($animated ? ['VP8X', 'ICCP', 'ANIM', 'ANMF', 'ANMF', 'EXIF'] : ['VP8X', 'ICCP', $fourcc, 'EXIF'])
            ->and($flags)->toBe(0x20 | 0x08 | ($animated ? 0x02 : 0))
            ->and(webpChunk($image->bytes, 'ICCP'))->toBe(ImageFixtures::icc())
            ->and(webpChunk($image->bytes, 'EXIF'))->toBe(ExifOrientation::minimalTiff(6))
            ->and($image->bytes)->toContain(ImageFixtures::riffChunk($fourcc, $bitstream))
            ->and(strlen($image->bytes))->toBe(8 + (int) unpack('V', $image->bytes, 4)[1]);
        if (! $animated) {
            expect(rgbAt($image->bytes, 40, 28))->toBe(rgbAt(ImageFixtures::gdWebp($lossless), 40, 28));
        }
    })->with([
        'lossy' => [false, false],
        'lossless' => [true, false],
        'animated' => [false, true],
    ]);

    it('passes the simple format through: it has no room for metadata', function (): void {
        $simple = ImageFixtures::gdWebp();
        $image = sanitizeImage($simple.'CANARY-AFTER-RIFF');

        expect($image->bytes)->toBe($simple)->and($image->orientation)->toBe(1);
    });

    it('refuses a truncated container', function (): void {
        expect(fn () => sanitizeImage(substr(ImageFixtures::webp(), 0, 60)))->toThrow(InvalidImage::class);
    });
});

it('keeps an orientation of 2 to 8 as the only EXIF entry and none at all for 1, with the pixel bytes untouched', function (int $orientation): void {
    $keeps = $orientation !== 1;
    $turned = $orientation >= 5;

    $jpeg = sanitizeImage(ImageFixtures::jpeg($orientation));
    $png = sanitizeImage(ImageFixtures::png($orientation));
    $webp = sanitizeImage(ImageFixtures::webp($orientation));

    foreach ([$jpeg, $png, $webp] as $image) {
        expect($image->orientation)->toBe($orientation)
            ->and([$image->width, $image->height])->toBe($turned ? [ImageFixtures::HEIGHT, ImageFixtures::WIDTH] : [ImageFixtures::WIDTH, ImageFixtures::HEIGHT])
            ->and($image->bytes)->not->toContain(ImageFixtures::CANARY)
            ->and(substr_count($image->bytes, ExifOrientation::minimalTiff($orientation)))->toBe($keeps ? 1 : 0)
            ->and(str_contains($image->bytes, "MM\0*"))->toBe($keeps);
    }

    expect(substr_count($jpeg->bytes, "Exif\0\0"))->toBe($keeps ? 1 : 0)
        ->and(ImageFixtures::jpegScans($jpeg->bytes))->toBe(ImageFixtures::jpegScans(ImageFixtures::gdJpeg()))
        ->and(ImageFixtures::pngIdat($png->bytes))->toBe(ImageFixtures::pngIdat(ImageFixtures::gdPng()))
        ->and(str_contains($png->bytes, 'eXIf'))->toBe($keeps)
        ->and(webpChunk($webp->bytes, 'EXIF'))->toBe($keeps ? ExifOrientation::minimalTiff($orientation) : null)
        ->and(ord((string) webpChunk($webp->bytes, 'VP8X')) & 0x08)->toBe($keeps ? 0x08 : 0)
        ->and($webp->bytes)->toContain(ImageFixtures::riffChunk(...ImageFixtures::webpBitstream()));
})->with([1, 2, 3, 4, 5, 6, 7, 8]);

it('reads the type from the bytes, and turns AVIF and HEIC away with their own message', function (string $bytes, string $message): void {
    expect(fn () => sanitizeImage($bytes))->toThrow(function (InvalidImage $exception) use ($message): void {
        expect($exception->messageFor('foto.jpg'))->toContain('«foto.jpg»')->toContain($message);
    });
})->with([
    'a PDF named .jpg' => ["%PDF-1.7\n1 0 obj\n<<>>\nendobj\n", 'non è una foto JPEG, PNG o WebP'],
    'a GIF' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'non è una foto JPEG, PNG o WebP'],
    'an MP4 video' => ["\0\0\0\x18ftypisom\0\0\x02\0isomiso2".str_repeat("\0", 16), 'non è una foto JPEG, PNG o WebP'],
    'AVIF' => ["\0\0\0\x1Cftypavif\0\0\0\0avifmif1miaf".str_repeat("\0", 16), 'AVIF o HEIC'],
    'AVIF with mif1 as its major brand' => ["\0\0\0\x18ftypmif1\0\0\0\0mif1avif".str_repeat("\0", 16), 'AVIF o HEIC'],
    'HEIC' => ["\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 16), 'AVIF o HEIC'],
]);

it('refuses a type the configuration leaves out', function (): void {
    expect(fn () => (new ImageSanitizer)->sanitize(ImageFixtures::gdPng(), [ImageFormat::Jpeg], 50))
        ->toThrow(InvalidImage::class);
});

it('caps the megapixels from the header, without decoding a byte', function (): void {
    expect(sanitizeImage(ImageFixtures::hugePng(10_000, 5_000))->width)->toBe(10_000)
        ->and(fn () => sanitizeImage(ImageFixtures::hugePng(10_000, 5_001)))->toThrow(function (InvalidImage $exception): void {
            expect($exception->messageFor('x.png'))->toContain('al massimo 50 megapixel');
        });
});

it('refuses a file that still carries metadata where the format code can\'t see it', function (string $planted): void {
    expect(fn () => sanitizeImage(ImageFixtures::pngWithPlantedBytes($planted)))->toThrow(function (InvalidImage $exception): void {
        expect($exception->messageFor('x.png'))->toContain('togliere i dati nascosti');
    });
})->with([
    'an XMP packet' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/">CANARY</x:xmpmeta>'],
    'a TIFF header' => ["II*\0\x08\0\0\0\x01\0\x12\x01\x03\0"],
    'an EXIF header' => ["Exif\0\0MM"],
]);

it('writes the smallest EXIF that keeps an orientation, and reads its own and others\' back', function (): void {
    foreach (range(1, 8) as $orientation) {
        expect(strlen(ExifOrientation::minimalTiff($orientation)))->toBe(26)
            ->and(ExifOrientation::read(ExifOrientation::minimalTiff($orientation)))->toBe($orientation)
            ->and(ExifOrientation::read("Exif\0\0".ExifOrientation::minimalTiff($orientation)))->toBe($orientation)
            ->and(ExifOrientation::read(ImageFixtures::exif($orientation, false)))->toBe($orientation);
    }

    expect(ExifOrientation::read(''))->toBe(1)
        ->and(ExifOrientation::read('not a tiff structure'))->toBe(1)
        ->and(ExifOrientation::read(ExifOrientation::minimalTiff(0)))->toBe(1)
        ->and(ExifOrientation::read(ExifOrientation::minimalTiff(9)))->toBe(1)
        ->and(ExifOrientation::keeps(1))->toBeFalse()
        ->and(ExifOrientation::keeps(6))->toBeTrue();
});
