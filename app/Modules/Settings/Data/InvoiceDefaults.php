<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

use App\Kernel\Vat\Natura;
use App\Support\Shape;

/**
 * What new invoices start from. Documents copy these values at issue:
 * changing a default never changes an issued document.
 */
final readonly class InvoiceDefaults
{
    public const string DEFAULT_LEGAL_WORDING = 'Operazione effettuata ai sensi dell\'art. 1, commi da 54 a 89, della Legge n. 190/2014 – Regime forfettario. Operazione non soggetta a ritenuta d\'acconto ai sensi dell\'art. 1, comma 67, della Legge n. 190/2014.';

    public function __construct(
        public int $paymentTermsDays = 30,
        public ?Natura $defaultNatura = Natura::N2_2,
        public ?string $legalWording = self::DEFAULT_LEGAL_WORDING,
        public bool $stampDutyChargedToCustomer = false,
        public bool $inpsContributionEnabled = false,
        public int $inpsContributionBasisPoints = 400,
        public string $invoiceSeries = NumberingSeries::DEFAULT_SERIES,
        public string $creditNoteSeries = NumberingSeries::DEFAULT_SERIES,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $natura = Shape::nullableString($data, 'default_natura');

        return new self(
            paymentTermsDays: max(0, Shape::int($data, 'payment_terms_days', 30)),
            defaultNatura: $natura === null ? null : Natura::tryFrom($natura),
            legalWording: Shape::nullableString($data, 'legal_wording'),
            stampDutyChargedToCustomer: Shape::bool($data, 'stamp_duty_charged_to_customer'),
            inpsContributionEnabled: Shape::bool($data, 'inps_contribution_enabled'),
            inpsContributionBasisPoints: max(0, Shape::int($data, 'inps_contribution_basis_points', 400)),
            invoiceSeries: Shape::nullableString($data, 'invoice_series') ?? NumberingSeries::DEFAULT_SERIES,
            creditNoteSeries: Shape::nullableString($data, 'credit_note_series') ?? NumberingSeries::DEFAULT_SERIES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_terms_days' => $this->paymentTermsDays,
            'default_natura' => $this->defaultNatura?->value,
            'legal_wording' => $this->legalWording,
            'stamp_duty_charged_to_customer' => $this->stampDutyChargedToCustomer,
            'inps_contribution_enabled' => $this->inpsContributionEnabled,
            'inps_contribution_basis_points' => $this->inpsContributionBasisPoints,
            'invoice_series' => $this->invoiceSeries,
            'credit_note_series' => $this->creditNoteSeries,
        ];
    }
}
