<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Enums\ImageFormat;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one class that touches the attachments' disk (pipeline.attachments
 * .disk, docs/pipeline.md §10): private, never served by URL, shared by
 * the app (writes, serves through the authorised routes), the worker
 * (reads, mounted read-only) and the scheduler (prunes). Stored names
 * are generated (ULID + the extension of the real type) and checked
 * before they reach a path; the client's file name never does.
 */
final readonly class AttachmentStore
{
    private const string NAME = '/^[0-9a-z]{26}\.(jpg|png|webp)$/';

    public function __construct(
        private FilesystemFactory $filesystems,
        private PipelineConfig $config,
    ) {}

    /**
     * Stores the bytes under a fresh name, and returns the name.
     */
    public function put(string $bytes, ImageFormat $format): string
    {
        $name = mb_strtolower((string) Str::ulid()).'.'.$format->extension();
        if (! $this->disk()->put($name, $bytes)) {
            throw new RuntimeException("Could not store the attachment {$name}.");
        }

        return $name;
    }

    public function get(string $storedPath): string
    {
        $bytes = $this->disk()->get(self::checked($storedPath));
        if ($bytes === null) {
            throw new RuntimeException("The attachment {$storedPath} is not on the disk.");
        }

        return $bytes;
    }

    /**
     * @return resource
     */
    public function readStream(string $storedPath)
    {
        $stream = $this->disk()->readStream(self::checked($storedPath));
        if (! is_resource($stream)) {
            throw new RuntimeException("The attachment {$storedPath} is not on the disk.");
        }

        return $stream;
    }

    public function exists(string $storedPath): bool
    {
        return $this->disk()->exists(self::checked($storedPath));
    }

    /**
     * Gone afterwards, whether it was there or not.
     */
    public function delete(string $storedPath): void
    {
        $path = self::checked($storedPath);
        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * The stored files and when each was last written (Unix time): what
     * the retention job compares with the rows. Anything not named like
     * an attachment is left alone.
     *
     * @return array<string, int>
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->disk()->files() as $path) {
            if (preg_match(self::NAME, $path) === 1) {
                $files[$path] = $this->disk()->lastModified($path);
            }
        }

        return $files;
    }

    /**
     * The image itself, for the authorised routes only: inline, typed by
     * what it really is, never sniffed, never run as a document, never
     * kept by a shared cache.
     */
    public function response(string $storedPath, ImageFormat $format, string $downloadName): StreamedResponse
    {
        $size = $this->disk()->size(self::checked($storedPath));
        $stream = $this->readStream($storedPath);

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $format->value,
            'Content-Length' => (string) $size,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $downloadName, Str::ascii($downloadName)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public static function isStoredName(string $storedPath): bool
    {
        return preg_match(self::NAME, $storedPath) === 1;
    }

    private static function checked(string $storedPath): string
    {
        if (! self::isStoredName($storedPath)) {
            throw new RuntimeException("Not an attachment's stored name: \"{$storedPath}\".");
        }

        return $storedPath;
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->config->attachmentsDisk());
    }
}
