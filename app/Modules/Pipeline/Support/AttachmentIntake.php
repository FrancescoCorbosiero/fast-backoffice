<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\AttachmentUpload;
use App\Modules\Pipeline\Support\Images\ImageSanitizer;
use App\Modules\Pipeline\Support\Images\InvalidImage;
use App\Modules\Pipeline\Support\Images\SanitizedImage;

/**
 * The photos of a new request, checked and cleaned before anything is
 * stored (docs/pipeline.md §10): how many, how big one by one and in
 * all, what they really are, how many megapixels, and their metadata
 * stripped. Every problem is collected, so the client fixes them in one
 * go: they have to choose the photos again after any error.
 */
final readonly class AttachmentIntake
{
    public function __construct(
        private ImageSanitizer $sanitizer,
        private PipelineConfig $config,
    ) {}

    /**
     * @param  list<AttachmentUpload>  $uploads
     * @param  int  $alreadyAttached  photos copied from an earlier request, which count toward the limits
     * @param  int  $alreadyBytes  their size
     * @return array{errors: list<string>, images: list<array{name: string, image: SanitizedImage}>}
     */
    public function prepare(array $uploads, int $alreadyAttached = 0, int $alreadyBytes = 0): array
    {
        $max = $this->config->maxAttachments();
        if (count($uploads) + $alreadyAttached > $max) {
            return ['errors' => ["Puoi allegare al massimo {$max} foto."], 'images' => []];
        }

        $maxMb = $this->config->maxAttachmentMb();
        $errors = [];
        $sizes = [];
        foreach ($uploads as $index => $upload) {
            $name = self::cleanName($upload->originalName);
            if ($upload->isTooLarge()) {
                $errors[] = "«{$name}» è troppo grande: al massimo {$maxMb} MB.";
            } elseif ($upload->error !== UPLOAD_ERR_OK || $upload->path === null) {
                $errors[] = "«{$name}» non è arrivata per intero: riprova.";
            } else {
                $size = filesize($upload->path);
                if ($size === false || $size === 0) {
                    $errors[] = "«{$name}» è vuota: sceglila di nuovo.";
                } elseif ($size > $this->config->maxAttachmentBytes()) {
                    $errors[] = "«{$name}» è troppo grande: al massimo {$maxMb} MB.";
                } else {
                    $sizes[$index] = $size;
                }
            }
        }

        $totalMb = $this->config->maxAttachmentsTotalMb();
        if (array_sum($sizes) + $alreadyBytes > $this->config->maxAttachmentsTotalBytes()) {
            $errors[] = "Le foto pesano più di {$totalMb} MB in tutto: allegane meno, o riducile, e riprova.";

            return ['errors' => $errors, 'images' => []];
        }

        $images = [];
        foreach ($sizes as $index => $size) {
            $upload = $uploads[$index];
            $name = self::cleanName($upload->originalName);
            $bytes = file_get_contents((string) $upload->path);
            if ($bytes === false || strlen($bytes) !== $size) {
                $errors[] = "«{$name}» non è arrivata per intero: riprova.";

                continue;
            }

            try {
                $images[] = ['name' => $name, 'image' => $this->sanitizer->sanitize($bytes, $this->config->attachmentFormats(), $this->config->maxAttachmentMegapixels())];
            } catch (InvalidImage $exception) {
                $errors[] = $exception->messageFor($name);
            }
            unset($bytes);
        }

        return ['errors' => $errors, 'images' => $errors === [] ? $images : []];
    }

    /**
     * The client's file name, for display only: valid UTF-8, its last
     * path segment, no control or formatting characters (no right-to-left
     * override to disguise an extension), at most 255 characters.
     */
    public static function cleanName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = str_replace('\\', '/', $name);
        $name = substr($name, (int) strrpos('/'.$name, '/'));
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $name = mb_substr($name, 0, 255);

        return $name === '' ? 'foto' : $name;
    }
}
