<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\FatturaPa;

use App\Kernel\Fiscal\VatNumber;
use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\FatturaPaFile;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Data\VatSummaryLine;
use App\Modules\Invoicing\Enums\DocumentType;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads a FatturaPA file into the module's own shapes, whatever namespace
 * prefix it carries. Totals come from the file's own DatiRiepilogo, not
 * recomputed: a received invoice is what the supplier declared.
 */
final class FatturaPaReader
{
    /**
     * @throws InvalidArgumentException when the file is not a FatturaPA document
     */
    public function read(string $xml): FatturaPaFile
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if (trim($xml) === '' || ! $document->loadXML($xml)) {
                throw new InvalidArgumentException('Il file non è un XML valido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'FatturaElettronica') {
            throw new InvalidArgumentException('Il file non è una fattura elettronica.');
        }

        $header = $this->child($root, 'FatturaElettronicaHeader') ?? throw new InvalidArgumentException('Manca FatturaElettronicaHeader.');
        $body = $this->child($root, 'FatturaElettronicaBody') ?? throw new InvalidArgumentException('Manca FatturaElettronicaBody.');
        $general = $this->descendant($body, 'DatiGenerali/DatiGeneraliDocumento') ?? throw new InvalidArgumentException('Manca DatiGeneraliDocumento.');
        $currency = $this->text($general, 'Divisa') ?? 'EUR';

        $type = DocumentType::tryFrom($this->text($general, 'TipoDocumento') ?? '');
        if ($type === null) {
            throw new InvalidArgumentException('Tipo documento non gestito: '.($this->text($general, 'TipoDocumento') ?? '?').'.');
        }

        $lines = [];
        $goods = $this->child($body, 'DatiBeniServizi') ?? throw new InvalidArgumentException('Manca DatiBeniServizi.');
        foreach ($this->children($goods, 'DettaglioLinee') as $node) {
            $lines[] = $this->line($node, $currency);
        }

        $summary = [];
        foreach ($this->children($goods, 'DatiRiepilogo') as $node) {
            $natura = $this->text($node, 'Natura');
            $summary[] = new VatSummaryLine(
                vatRate: VatRate::fromPercentString($this->text($node, 'AliquotaIVA') ?? '0'),
                natura: $natura === null ? null : Natura::tryFrom($natura),
                taxable: $this->money($this->text($node, 'ImponibileImporto') ?? '0', $currency),
                vat: $this->money($this->text($node, 'Imposta') ?? '0', $currency),
            );
        }

        $taxable = Money::sum(array_map(fn (VatSummaryLine $line): Money => $line->taxable, $summary), $currency);
        $vat = Money::sum(array_map(fn (VatSummaryLine $line): Money => $line->vat, $summary), $currency);
        $bollo = $this->descendant($general, 'DatiBollo');
        $declared = $this->text($general, 'ImportoTotaleDocumento');

        $totals = new DocumentTotals(
            subtotal: $taxable,
            inps: Money::zero($currency),
            inpsBasisPoints: 0,
            inpsVatRate: null,
            inpsNatura: null,
            summary: $summary,
            vat: $vat,
            stampDutyDue: $bollo !== null,
            stampDutyCharged: Money::zero($currency),
            total: $taxable->plus($vat),
        );

        $due = null;
        $payment = $this->descendant($body, 'DatiPagamento/DettaglioPagamento');
        if ($payment !== null && ($dueText = $this->text($payment, 'DataScadenzaPagamento')) !== null) {
            $due = CarbonImmutable::parse($dueText);
        }

        $seller = $this->child($header, 'CedentePrestatore') ?? throw new InvalidArgumentException('Manca CedentePrestatore.');
        $customer = $this->child($header, 'CessionarioCommittente') ?? throw new InvalidArgumentException('Manca CessionarioCommittente.');

        return new FatturaPaFile(
            seller: $this->party($seller),
            customer: $this->party($customer),
            type: $type,
            number: trim($this->text($general, 'Numero') ?? ''),
            date: CarbonImmutable::parse($this->text($general, 'Data') ?? throw new InvalidArgumentException('Manca la data.')),
            currency: $currency,
            lines: $lines,
            totals: $totals,
            dueOn: $due,
            declaredTotal: $declared === null ? null : $this->money($declared, $currency),
            regime: $this->text($this->child($seller, 'DatiAnagrafici'), 'RegimeFiscale'),
        );
    }

    private function party(DOMElement $node): PartySnapshot
    {
        $anagrafici = $this->child($node, 'DatiAnagrafici');
        $anagrafica = $anagrafici === null ? null : $this->child($anagrafici, 'Anagrafica');
        $sede = $this->child($node, 'Sede');
        $idFiscale = $anagrafici === null ? null : $this->child($anagrafici, 'IdFiscaleIVA');

        $name = $this->text($anagrafica, 'Denominazione');
        $first = $this->text($anagrafica, 'Nome');
        $last = $this->text($anagrafica, 'Cognome');
        $vat = null;

        if ($idFiscale !== null) {
            try {
                $vat = new VatNumber($this->text($idFiscale, 'IdPaese') ?? 'IT', $this->text($idFiscale, 'IdCodice') ?? '');
            } catch (InvalidArgumentException) {
                $vat = null;
            }
        }

        return new PartySnapshot(
            name: $name ?? trim(($first ?? '').' '.($last ?? '')),
            isCompany: $name !== null,
            firstName: $name === null ? $first : null,
            lastName: $name === null ? $last : null,
            vatNumber: $vat,
            taxCode: $this->text($anagrafici, 'CodiceFiscale'),
            street: $this->text($sede, 'Indirizzo'),
            streetNumber: $this->text($sede, 'NumeroCivico'),
            postcode: $this->text($sede, 'CAP'),
            city: $this->text($sede, 'Comune'),
            province: $this->text($sede, 'Provincia'),
            country: $this->text($sede, 'Nazione') ?? 'IT',
            email: $this->text($this->child($node, 'Contatti'), 'Email'),
            pec: null,
        );
    }

    private function line(DOMElement $node, string $currency): LineInput
    {
        $natura = $this->text($node, 'Natura');
        $quantity = $this->text($node, 'Quantita');

        return new LineInput(
            description: $this->text($node, 'Descrizione') ?? '',
            quantity: $quantity === null ? Quantity::of(1) : $this->quantity($quantity),
            unitPrice: $this->money($this->text($node, 'PrezzoUnitario') ?? '0', $currency),
            vatRate: VatRate::fromPercentString($this->text($node, 'AliquotaIVA') ?? '0'),
            natura: $natura === null ? null : Natura::tryFrom($natura),
        );
    }

    private function money(string $value, string $currency): Money
    {
        // Amounts may carry up to 8 decimals; the cents are what matters.
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', trim($value), $parts) !== 1) {
            throw new InvalidArgumentException("Importo non leggibile: \"{$value}\".");
        }

        $fraction = substr(($parts[2] ?? '').'00', 0, 2);

        return Money::fromDecimal($parts[1].'.'.$fraction, $currency);
    }

    private function quantity(string $value): Quantity
    {
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', trim($value), $parts) !== 1) {
            throw new InvalidArgumentException("Quantità non leggibile: \"{$value}\".");
        }

        return Quantity::fromDecimal($parts[1].'.'.substr(($parts[2] ?? '').'0000', 0, 4));
    }

    private function child(?DOMNode $node, string $name): ?DOMElement
    {
        if ($node === null) {
            return null;
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMNode $node, string $name): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function descendant(DOMNode $node, string $path): ?DOMElement
    {
        $current = $node;
        foreach (explode('/', $path) as $name) {
            $current = $this->child($current, $name);
            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    private function text(?DOMNode $node, string $name): ?string
    {
        $child = $this->child($node, $name);
        $text = $child === null ? null : trim($child->textContent);

        return $text === null || $text === '' ? null : $text;
    }
}
