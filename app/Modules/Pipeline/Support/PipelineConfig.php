<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Enums\ImageFormat;
use Illuminate\Contracts\Config\Repository;

/**
 * Typed reads of config/pipeline.php, so no caller casts mixed.
 */
final readonly class PipelineConfig
{
    public function __construct(private Repository $config) {}

    public function workspaceRoot(): string
    {
        return rtrim($this->string('pipeline.workspace_root'), '/');
    }

    public function previewRoot(): string
    {
        return rtrim($this->string('pipeline.preview_root'), '/');
    }

    public function previewDomain(): string
    {
        return $this->string('pipeline.preview_domain');
    }

    public function claudeBinary(): string
    {
        $binary = $this->string('pipeline.claude_binary');

        return $binary === '' ? 'claude' : $binary;
    }

    public function claudeModel(): ?string
    {
        $model = $this->string('pipeline.claude_model');

        return $model === '' ? null : $model;
    }

    public function anthropicApiKey(): ?string
    {
        $key = $this->string('pipeline.anthropic_api_key');

        return $key === '' ? null : $key;
    }

    public function maxCostUsd(): float
    {
        $value = $this->config->get('pipeline.max_cost_usd', 5);

        return max(0.01, is_numeric($value) ? (float) $value : 5.0);
    }

    public function timeoutSeconds(): int
    {
        return max(60, $this->int('pipeline.timeout_seconds', 1800));
    }

    public function buildTimeoutSeconds(): int
    {
        return max(30, $this->int('pipeline.build_timeout_seconds', 900));
    }

    /**
     * How long a job may run, CLI and build included, with a margin.
     */
    public function workerTimeoutSeconds(): int
    {
        return $this->timeoutSeconds() + $this->buildTimeoutSeconds() + 300;
    }

    public function gitName(): string
    {
        return $this->string('pipeline.git.name');
    }

    public function gitEmail(): string
    {
        return $this->string('pipeline.git.email');
    }

    /**
     * @return array<string, string>
     */
    public function gitEnv(): array
    {
        $env = [];
        $values = $this->config->get('pipeline.git.env', []);
        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    public function queueConnection(): ?string
    {
        $value = $this->string('pipeline.queue.connection');

        return $value === '' ? null : $value;
    }

    public function queueName(): ?string
    {
        $value = $this->string('pipeline.queue.name');

        return $value === '' ? null : $value;
    }

    /**
     * The private disk the attachments live on (config/filesystems.php).
     */
    public function attachmentsDisk(): string
    {
        $disk = $this->string('pipeline.attachments.disk');

        return $disk === '' ? 'pipeline-uploads' : $disk;
    }

    public function maxAttachments(): int
    {
        return max(1, $this->int('pipeline.attachments.max_files', 10));
    }

    public function maxAttachmentMb(): int
    {
        return max(1, $this->int('pipeline.attachments.max_mb', 25));
    }

    public function maxAttachmentBytes(): int
    {
        return $this->maxAttachmentMb() * 1024 * 1024;
    }

    public function maxAttachmentsTotalMb(): int
    {
        return max(1, $this->int('pipeline.attachments.max_total_mb', 90));
    }

    public function maxAttachmentsTotalBytes(): int
    {
        return $this->maxAttachmentsTotalMb() * 1024 * 1024;
    }

    public function maxAttachmentMegapixels(): int
    {
        return max(1, $this->int('pipeline.attachments.max_megapixels', 50));
    }

    /**
     * The types the configuration allows, among the ones the sanitizer
     * handles: the list can only narrow them.
     *
     * @return list<ImageFormat>
     */
    public function attachmentFormats(): array
    {
        $formats = [];
        $mimes = $this->config->get('pipeline.attachments.mimes', []);
        foreach (is_array($mimes) ? $mimes : [] as $mime) {
            $format = is_string($mime) ? ImageFormat::tryFrom($mime) : null;
            if ($format !== null && ! in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * Days after a request ends (deployed, rejected, failed) before its
     * attachments' files are deleted.
     */
    public function attachmentKeepDays(): int
    {
        return max(1, $this->int('pipeline.attachments.keep_days', 90));
    }

    public function portalLinkMinutes(): int
    {
        return max(1, $this->int('pipeline.portal.link_minutes', 15));
    }

    public function portalMailer(): ?string
    {
        $value = $this->string('pipeline.portal.mailer');

        return $value === '' ? null : $value;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
