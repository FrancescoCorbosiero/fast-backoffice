<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Enums;

/**
 * What an accepted quote turns into, through Invoicing.
 */
enum AcceptanceOutcome: string
{
    case InvoiceDraft = 'invoice_draft';
    case PaymentRequest = 'payment_request';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceDraft => 'Bozza di fattura',
            self::PaymentRequest => 'Richiesta di pagamento',
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
