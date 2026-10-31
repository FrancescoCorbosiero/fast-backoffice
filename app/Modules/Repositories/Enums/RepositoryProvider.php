<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Enums;

enum RepositoryProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Other => 'Altro',
        };
    }

    public static function fromUrl(string $url): self
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_ends_with($host, 'github.com') => self::GitHub,
            str_ends_with($host, 'gitlab.com') => self::GitLab,
            str_ends_with($host, 'bitbucket.org') => self::Bitbucket,
            default => self::Other,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
