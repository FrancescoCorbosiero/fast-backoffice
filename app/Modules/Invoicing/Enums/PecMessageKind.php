<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

/**
 * What a PEC message turned out to be (sdi.md §5).
 */
enum PecMessageKind: string
{
    case PecAcceptance = 'pec_acceptance';
    case PecDelivery = 'pec_delivery';
    case PecError = 'pec_error';
    case SdiDelivered = 'sdi_rc';
    case SdiRejected = 'sdi_ns';
    case SdiNotDelivered = 'sdi_mc';
    case SdiAttestation = 'sdi_at';
    case SdiOther = 'sdi_other';
    case SupplierInvoice = 'supplier_invoice';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PecAcceptance => 'PEC: accettazione',
            self::PecDelivery => 'PEC: consegna a SdI',
            self::PecError => 'PEC: errore di consegna',
            self::SdiDelivered => 'SdI: ricevuta di consegna',
            self::SdiRejected => 'SdI: notifica di scarto',
            self::SdiNotDelivered => 'SdI: mancata consegna',
            self::SdiAttestation => 'SdI: attestazione di trasmissione',
            self::SdiOther => 'SdI: altra notifica',
            self::SupplierInvoice => 'Fattura fornitore',
            self::Unknown => 'Altro',
        };
    }

    /**
     * From the suffix of a notification file name: IT…_00001_RC_001.xml.
     */
    public static function fromNotificationCode(string $code): self
    {
        return match (strtoupper($code)) {
            'RC' => self::SdiDelivered,
            'NS' => self::SdiRejected,
            'MC' => self::SdiNotDelivered,
            'AT' => self::SdiAttestation,
            default => self::SdiOther,
        };
    }
}
