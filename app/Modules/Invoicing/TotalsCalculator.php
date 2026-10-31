<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Bill;
use App\Kernel\Vat\Line;
use App\Kernel\Vat\Natura;
use App\Kernel\Vat\VatGroup;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\VatSummaryLine;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Enums\RegimeFiscale;
use Illuminate\Validation\ValidationException;

/**
 * A document's figures through the Kernel's engine (design.md §5.1):
 * lines, then the INPS contribution (rivalsa, when enabled) added to the
 * taxable base of the main VAT group, the per-group VAT, the stamp duty
 * rule (sdi.md §7), and the total.
 */
final readonly class TotalsCalculator
{
    public const int STAMP_DUTY_CENTS = 200;

    public const int STAMP_DUTY_THRESHOLD_CENTS = 7_747;

    public const string STAMP_DUTY_LINE = 'Imposta di bollo';

    public const string INPS_LINE = 'Rivalsa INPS 4% (art. 1, c. 212, L. 662/1996)';

    /**
     * Fills the natura of 0% lines from the defaults and checks every line.
     *
     * @param  list<LineInput>  $lines
     * @return list<LineInput>
     *
     * @throws ValidationException
     */
    public function normalise(array $lines, InvoiceDefaults $defaults, RegimeFiscale $regime): array
    {
        $errors = [];
        $normalised = [];

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Serve almeno una riga.']);
        }

        foreach ($lines as $index => $line) {
            if ($line->description === '') {
                $errors["lines.{$index}.description"] = 'Ogni riga ha una descrizione.';
            }

            if ($line->quantity->isZero()) {
                $errors["lines.{$index}.quantity"] = 'La quantità non può essere zero.';
            }

            if ($regime === RegimeFiscale::Forfettario && ! $line->vatRate->isZero()) {
                $errors["lines.{$index}.vat_basis_points"] = 'Nel regime forfettario le righe non hanno IVA.';
            }

            if ($line->vatRate->isZero() && $line->natura === null) {
                if ($defaults->defaultNatura === null) {
                    $errors["lines.{$index}.natura"] = 'Una riga senza IVA ha una natura: scegline una, o imposta quella predefinita.';
                } else {
                    $line = $line->withNatura($defaults->defaultNatura);
                }
            }

            if (! $line->vatRate->isZero() && $line->natura !== null) {
                $line = $line->withNatura(null);
            }

            $normalised[] = $line;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalised;
    }

    /**
     * @param  list<LineInput>  $lines  already normalised
     */
    public function compute(array $lines, InvoiceDefaults $defaults, RegimeFiscale $regime, string $currency = 'EUR'): DocumentTotals
    {
        $kernelLines = array_map(fn (LineInput $line): Line => $line->toKernelLine(), $lines);
        $subtotal = (new Bill($kernelLines, $currency))->subtotal();

        // INPS: the contribution follows the VAT treatment of the main group.
        $inps = Money::zero($currency);
        $inpsRate = null;
        $inpsNatura = null;

        if ($defaults->inpsContributionEnabled && $defaults->inpsContributionBasisPoints > 0 && $subtotal->isPositive()) {
            $main = $this->mainGroup(new Bill($kernelLines, $currency));
            $inps = $subtotal->scaled($defaults->inpsContributionBasisPoints, VatRate::BASIS_POINTS_PER_UNIT);
            $inpsRate = $main->vatRate;
            $inpsNatura = $main->natura;
            $kernelLines[] = new Line(self::INPS_LINE, Quantity::of(1), $inps, $inpsRate, $inpsNatura);
        }

        $taxableBill = new Bill($kernelLines, $currency);
        $stampDutyDue = $this->stampDutyDue($taxableBill);
        $stampDutyCharged = Money::zero($currency);

        if ($stampDutyDue && $defaults->stampDutyChargedToCustomer) {
            $stampDutyCharged = new Money(self::STAMP_DUTY_CENTS, $currency);
            $kernelLines[] = new Line(self::STAMP_DUTY_LINE, Quantity::of(1), $stampDutyCharged, VatRate::zero(), Natura::N1);
        }

        $bill = new Bill($kernelLines, $currency);

        return new DocumentTotals(
            subtotal: $subtotal,
            inps: $inps,
            inpsBasisPoints: $inps->isPositive() ? $defaults->inpsContributionBasisPoints : 0,
            inpsVatRate: $inpsRate,
            inpsNatura: $inpsNatura,
            summary: array_map(fn (VatGroup $group): VatSummaryLine => VatSummaryLine::fromGroup($group), $bill->summary()),
            vat: $bill->vat(),
            stampDutyDue: $stampDutyDue,
            stampDutyCharged: $stampDutyCharged,
            total: $bill->total(),
        );
    }

    /**
     * Stamp duty is due when the lines whose natura owes it exceed €77.47
     * without VAT (sdi.md §7).
     */
    private function stampDutyDue(Bill $bill): bool
    {
        $exempt = 0;

        foreach ($bill->summary() as $group) {
            if ($group->natura?->stampDutyApplies() === true) {
                $exempt += $group->taxable->cents;
            }
        }

        return $exempt > self::STAMP_DUTY_THRESHOLD_CENTS;
    }

    private function mainGroup(Bill $bill): VatGroup
    {
        $groups = $bill->summary();
        $main = $groups[0];

        foreach ($groups as $group) {
            if ($group->taxable->greaterThan($main->taxable)) {
                $main = $group;
            }
        }

        return $main;
    }
}
