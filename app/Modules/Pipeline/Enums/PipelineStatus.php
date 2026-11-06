<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * Whether a website's repository can take change requests: not yet, being
 * audited (the onboarding request is waiting for approval), or ready.
 */
enum PipelineStatus: string
{
    case None = 'none';
    case Onboarding = 'onboarding';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Non configurata',
            self::Onboarding => 'In onboarding',
            self::Ready => 'Pronta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Onboarding => 'warning',
            self::Ready => 'success',
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
