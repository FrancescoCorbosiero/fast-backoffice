<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum ChangeRequestKind: string
{
    case Change = 'change';
    case Onboarding = 'onboarding';

    public function label(): string
    {
        return match ($this) {
            self::Change => 'Modifica',
            self::Onboarding => 'Onboarding',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Change => 'primary',
            self::Onboarding => 'gray',
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
