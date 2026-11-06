<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * How a website is built and deployed. Only static sites for now: the
 * repository's pipeline.yml names a build command and an output folder.
 */
enum PipelineKind: string
{
    case StaticSite = 'static_site';

    public function label(): string
    {
        return match ($this) {
            self::StaticSite => 'Sito statico',
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
