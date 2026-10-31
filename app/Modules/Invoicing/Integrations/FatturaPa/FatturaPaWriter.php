<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\FatturaPa;

use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Modules\Invoicing\Data\FatturaPaDocument;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Data\VatSummaryLine;
use App\Modules\Invoicing\TotalsCalculator;
use XMLWriter;

/**
 * Builds the FPR12 XML with XMLWriter, never by string concatenation
 * (sdi.md §4). Element order follows the XSD.
 */
final class FatturaPaWriter
{
    public const string NAMESPACE = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    public const string FORMAT = 'FPR12';

    public function write(FatturaPaDocument $document): string
    {
        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $w->startElementNs('p', 'FatturaElettronica', self::NAMESPACE);
        $w->writeAttribute('versione', self::FORMAT);

        $this->header($w, $document);
        $this->body($w, $document);

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    private function header(XMLWriter $w, FatturaPaDocument $d): void
    {
        $w->startElement('FatturaElettronicaHeader');

        $w->startElement('DatiTrasmissione');
        $w->startElement('IdTrasmittente');
        $w->writeElement('IdPaese', 'IT');
        $w->writeElement('IdCodice', FatturaPaDocument::transmitterCode($d->seller));
        $w->endElement();
        $w->writeElement('ProgressivoInvio', $d->progressivoInvio);
        $w->writeElement('FormatoTrasmissione', self::FORMAT);
        $w->writeElement('CodiceDestinatario', $d->sdiAddress->codiceDestinatario);
        if ($d->sdiAddress->pec !== null) {
            $w->writeElement('PECDestinatario', $d->sdiAddress->pec);
        }
        $w->endElement();

        $w->startElement('CedentePrestatore');
        $w->startElement('DatiAnagrafici');
        if ($d->seller->vatNumber !== null) {
            $this->idFiscaleIva($w, $d->seller->vatNumber->country, $d->seller->vatNumber->number);
        }
        if ($d->seller->taxCode !== null) {
            $w->writeElement('CodiceFiscale', $d->seller->taxCode);
        }
        $this->anagrafica($w, $d->seller);
        $w->writeElement('RegimeFiscale', $d->regime);
        $w->endElement();
        $this->sede($w, $d->seller);
        if ($d->seller->phone !== null || $d->seller->email !== null) {
            $w->startElement('Contatti');
            if ($d->seller->phone !== null) {
                $w->writeElement('Telefono', $this->clip(preg_replace('/[^0-9+ ]/', '', $d->seller->phone) ?? '', 12));
            }
            if ($d->seller->email !== null) {
                $w->writeElement('Email', $d->seller->email);
            }
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('CessionarioCommittente');
        $w->startElement('DatiAnagrafici');
        if ($d->customer->vatNumber !== null) {
            $this->idFiscaleIva($w, $d->customer->vatNumber->country, $d->customer->vatNumber->number);
        }
        if ($d->customer->taxCode !== null && ! $d->customer->isForeign()) {
            $w->writeElement('CodiceFiscale', $d->customer->taxCode);
        }
        $this->anagrafica($w, $d->customer);
        $w->endElement();
        $this->sede($w, $d->customer);
        $w->endElement();

        $w->endElement();
    }

    private function body(XMLWriter $w, FatturaPaDocument $d): void
    {
        $w->startElement('FatturaElettronicaBody');

        $w->startElement('DatiGenerali');
        $w->startElement('DatiGeneraliDocumento');
        $w->writeElement('TipoDocumento', $d->type->value);
        $w->writeElement('Divisa', $d->currency);
        $w->writeElement('Data', $d->date->toDateString());
        $w->writeElement('Numero', $this->clip($d->number, 20));

        if ($d->totals->stampDutyDue) {
            $w->startElement('DatiBollo');
            $w->writeElement('BolloVirtuale', 'SI');
            $w->writeElement('ImportoBollo', '2.00');
            $w->endElement();
        }

        if ($d->totals->hasInps() && $d->totals->inpsVatRate !== null) {
            $w->startElement('DatiCassaPrevidenziale');
            $w->writeElement('TipoCassa', 'TC22');
            $w->writeElement('AlCassa', $this->percent($d->totals->inpsBasisPoints));
            $w->writeElement('ImportoContributoCassa', $d->totals->inps->toDecimalString());
            $w->writeElement('ImponibileCassa', $d->totals->subtotal->toDecimalString());
            $w->writeElement('AliquotaIVA', $d->totals->inpsVatRate->toPercentString());
            if ($d->totals->inpsNatura !== null) {
                $w->writeElement('Natura', $d->totals->inpsNatura->value);
            }
            $w->endElement();
        }

        $w->writeElement('ImportoTotaleDocumento', $d->totals->total->toDecimalString());

        foreach ($d->causale as $causale) {
            foreach (explode("\n", wordwrap($this->latin($causale), 200, "\n", true)) as $chunk) {
                $w->writeElement('Causale', $chunk);
            }
        }
        $w->endElement();

        if ($d->relatedNumber !== null) {
            $w->startElement('DatiFattureCollegate');
            $w->writeElement('IdDocumento', $this->clip($d->relatedNumber, 20));
            if ($d->relatedDate !== null) {
                $w->writeElement('Data', $d->relatedDate->toDateString());
            }
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('DatiBeniServizi');
        $number = 0;
        foreach ($d->lines as $line) {
            $this->line($w, ++$number, $line);
        }
        if ($d->totals->stampDutyCharged->isPositive()) {
            $this->line($w, ++$number, new LineInput(TotalsCalculator::STAMP_DUTY_LINE, Quantity::of(1), $d->totals->stampDutyCharged, VatRate::zero(), Natura::N1));
        }
        foreach ($d->totals->summary as $summary) {
            $this->summary($w, $summary);
        }
        $w->endElement();

        if ($d->amountDue()->isPositive()) {
            $w->startElement('DatiPagamento');
            $w->writeElement('CondizioniPagamento', 'TP02');
            $w->startElement('DettaglioPagamento');
            $w->writeElement('ModalitaPagamento', 'MP05');
            if ($d->dueOn !== null) {
                $w->writeElement('DataScadenzaPagamento', $d->dueOn->toDateString());
            }
            $w->writeElement('ImportoPagamento', $d->amountDue()->toDecimalString());
            if ($d->seller->iban !== null) {
                $w->writeElement('IBAN', $d->seller->iban);
            }
            $w->endElement();
            $w->endElement();
        }

        $w->endElement();
    }

    private function line(XMLWriter $w, int $number, LineInput $line): void
    {
        $w->startElement('DettaglioLinee');
        $w->writeElement('NumeroLinea', (string) $number);
        $w->writeElement('Descrizione', $this->clip(str_replace(["\r\n", "\n"], ' ', $line->description), 1000));
        $w->writeElement('Quantita', $line->quantity->toDecimalString(4));
        $w->writeElement('PrezzoUnitario', $line->unitPrice->toDecimalString());
        $w->writeElement('PrezzoTotale', $line->net()->toDecimalString());
        $w->writeElement('AliquotaIVA', $line->vatRate->toPercentString());
        if ($line->natura !== null) {
            $w->writeElement('Natura', $line->natura->value);
        }
        $w->endElement();
    }

    private function summary(XMLWriter $w, VatSummaryLine $summary): void
    {
        $w->startElement('DatiRiepilogo');
        $w->writeElement('AliquotaIVA', $summary->vatRate->toPercentString());
        if ($summary->natura !== null) {
            $w->writeElement('Natura', $summary->natura->value);
        }
        $w->writeElement('ImponibileImporto', $summary->taxable->toDecimalString());
        $w->writeElement('Imposta', $summary->vat->toDecimalString());
        if ($summary->natura === Natura::N2_2) {
            $w->writeElement('RiferimentoNormativo', 'Art. 1, commi 54-89, L. 190/2014 (regime forfettario)');
        }
        $w->endElement();
    }

    private function idFiscaleIva(XMLWriter $w, string $country, string $number): void
    {
        $w->startElement('IdFiscaleIVA');
        $w->writeElement('IdPaese', $country);
        $w->writeElement('IdCodice', $number);
        $w->endElement();
    }

    private function anagrafica(XMLWriter $w, PartySnapshot $party): void
    {
        $w->startElement('Anagrafica');
        if ($party->isCompany || $party->firstName === null || $party->lastName === null) {
            $w->writeElement('Denominazione', $this->clip($party->name, 80));
        } else {
            $w->writeElement('Nome', $this->clip($party->firstName, 60));
            $w->writeElement('Cognome', $this->clip($party->lastName, 60));
        }
        $w->endElement();
    }

    private function sede(XMLWriter $w, PartySnapshot $party): void
    {
        $w->startElement('Sede');
        $w->writeElement('Indirizzo', $this->clip($party->street ?? '', 60));
        if ($party->streetNumber !== null) {
            $w->writeElement('NumeroCivico', $this->clip($party->streetNumber, 8));
        }
        // Abroad, CAP is 00000 and the province is omitted (sdi.md §4).
        $w->writeElement('CAP', $party->isForeign() ? '00000' : $this->clip($party->postcode ?? '', 5));
        $w->writeElement('Comune', $this->clip($party->city ?? '', 60));
        if (! $party->isForeign() && $party->province !== null) {
            $w->writeElement('Provincia', strtoupper(substr($party->province, 0, 2)));
        }
        $w->writeElement('Nazione', $party->country);
        $w->endElement();
    }

    private function percent(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private function clip(string $value, int $length): string
    {
        return mb_substr(trim($this->latin($value)), 0, $length);
    }

    /**
     * The schema allows Basic Latin and Latin-1 only: typographic dashes
     * and quotes become their plain cousins, anything else is dropped.
     */
    private function latin(string $value): string
    {
        $value = strtr($value, ['–' => '-', '—' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '…' => '...', '€' => 'EUR']);

        return preg_replace('/[^\x{0000}-\x{00FF}]/u', '', $value) ?? '';
    }
}
