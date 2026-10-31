<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Queries\GetBusinessProfile;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use Illuminate\Validation\ValidationException;

/**
 * What every document use case needs from Settings and Contacts, and the
 * one way lines become totals.
 */
final readonly class DocumentContext
{
    public function __construct(
        private GetBusinessProfile $businessProfile,
        private GetInvoiceDefaults $invoiceDefaults,
        private GetNumberingSeries $numberingSeries,
        private FindContact $contacts,
        private TotalsCalculator $calculator,
    ) {}

    public function profile(): BusinessProfile
    {
        return $this->businessProfile->get();
    }

    public function defaults(): InvoiceDefaults
    {
        return $this->invoiceDefaults->get();
    }

    public function series(): NumberingSeries
    {
        return $this->numberingSeries->get();
    }

    /**
     * @throws ValidationException
     */
    public function customer(int $contactId): ContactData
    {
        return $this->contacts->byId($contactId)
            ?? throw ValidationException::withMessages(['contact_id' => 'Scegli un contatto.']);
    }

    /**
     * The lines with their natura filled in, and the totals.
     *
     * @param  list<LineInput>  $lines
     * @return array{lines: list<LineInput>, totals: DocumentTotals}
     *
     * @throws ValidationException
     */
    public function compute(array $lines, string $currency, bool $chargeStampDuty = true): array
    {
        $defaults = $this->defaults();

        if (! $chargeStampDuty) {
            $defaults = new InvoiceDefaults(
                paymentTermsDays: $defaults->paymentTermsDays,
                defaultNatura: $defaults->defaultNatura,
                legalWording: $defaults->legalWording,
                stampDutyChargedToCustomer: false,
                inpsContributionEnabled: $defaults->inpsContributionEnabled,
                inpsContributionBasisPoints: $defaults->inpsContributionBasisPoints,
                invoiceSeries: $defaults->invoiceSeries,
                creditNoteSeries: $defaults->creditNoteSeries,
            );
        }

        $regime = $this->profile()->regime;
        $normalised = $this->calculator->normalise($lines, $defaults, $regime);

        return ['lines' => $normalised, 'totals' => $this->calculator->compute($normalised, $defaults, $regime, $currency)];
    }
}
