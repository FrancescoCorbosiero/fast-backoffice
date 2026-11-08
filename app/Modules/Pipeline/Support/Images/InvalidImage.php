<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support\Images;

use RuntimeException;

/**
 * A file the sanitizer won't store, with the sentence the client reads
 * (Italian, "tu" form) once the file's name is filled in.
 */
final class InvalidImage extends RuntimeException
{
    private function __construct(private readonly string $template, string $detail)
    {
        parent::__construct($detail);
    }

    public static function notAnImage(): self
    {
        return new self('«%s» non è una foto JPEG, PNG o WebP.', 'not a JPEG, PNG or WebP file');
    }

    public static function isoMedia(): self
    {
        return new self('«%s» è in formato AVIF o HEIC: esportala in JPEG e riprova.', 'an ISO media image (AVIF, HEIC, HEIF)');
    }

    public static function unreadable(string $detail): self
    {
        return new self('Non riusciamo a leggere «%s»: esportala di nuovo dal telefono o dal computer e riprova.', $detail);
    }

    public static function tooManyPixels(int $megapixels): self
    {
        return new self('«%s» è troppo grande: al massimo '.$megapixels.' megapixel. Riducila e riprova.', 'more than '.$megapixels.' megapixels');
    }

    public static function notCleaned(string $detail): self
    {
        return new self('Non riusciamo a togliere i dati nascosti da «%s»: esportala di nuovo, per esempio in JPEG, e riprova.', $detail);
    }

    /**
     * The sentence for the client, about the file they called $name.
     */
    public function messageFor(string $name): string
    {
        return sprintf($this->template, $name);
    }
}
