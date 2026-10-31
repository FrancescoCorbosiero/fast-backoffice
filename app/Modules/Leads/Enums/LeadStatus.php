<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuovo',
            self::Contacted => 'Contattato',
            self::Qualified => 'Qualificato',
            self::Won => 'Vinto',
            self::Lost => 'Perso',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Contacted => 'info',
            self::Qualified => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    /**
     * Still in the pipeline.
     */
    public function isOpen(): bool
    {
        return $this !== self::Won && $this !== self::Lost;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::Contacted, self::Qualified];
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
