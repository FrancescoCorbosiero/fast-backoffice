<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * One image as it arrives with a change request, from the portal's form
 * or the panel's upload field: the client's file name, the local file to
 * read, and PHP's upload error when the file didn't arrive whole.
 */
final readonly class AttachmentUpload
{
    public function __construct(
        public string $originalName,
        public ?string $path,
        public int $error = UPLOAD_ERR_OK,
    ) {}

    /**
     * Symfony's uploads and Livewire's temporary ones alike. A file that
     * isn't on the local disk counts as not arrived.
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        $error = $file->getError();
        $path = $error === UPLOAD_ERR_OK ? $file->getRealPath() : false;
        $readable = is_string($path) && is_file($path);

        return new self(
            originalName: $file->getClientOriginalName(),
            path: $readable ? $path : null,
            error: $error === UPLOAD_ERR_OK && ! $readable ? UPLOAD_ERR_PARTIAL : $error,
        );
    }

    /**
     * The file didn't arrive because it is bigger than PHP accepts.
     */
    public function isTooLarge(): bool
    {
        return in_array($this->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }
}
