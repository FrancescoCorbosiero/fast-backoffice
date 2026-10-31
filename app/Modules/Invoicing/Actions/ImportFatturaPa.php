<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Kernel\Numbering\NumberSequence;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Actions\CreateContact;
use App\Modules\Contacts\Data\Address;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Invoicing\Data\FatturaPaFile;
use App\Modules\Invoicing\Data\ImportResult;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\SendChannel;
use App\Modules\Invoicing\Events\SupplierInvoiceReceived;
use App\Modules\Invoicing\Integrations\FatturaPa\FatturaPaReader;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\SupplierInvoice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Throwable;

/**
 * Imports a FatturaPA file: a supplier invoice when the owner is the
 * customer, or one of the owner's own invoices issued elsewhere this
 * year when the owner is the seller (recorded as sent, and the numbering
 * continues after it). Idempotent per document; nothing is written on
 * failure.
 */
final readonly class ImportFatturaPa
{
    public function __construct(
        private ConnectionInterface $db,
        private FatturaPaReader $reader,
        private DocumentContext $context,
        private FindContact $contacts,
        private CreateContact $createContact,
        private StoreFile $files,
        private NumberSequence $sequence,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function import(string $fileName, string $xml): ImportResult
    {
        try {
            $parsed = $this->reader->read($xml);
        } catch (InvalidArgumentException $exception) {
            return ImportResult::failed($fileName, $exception->getMessage());
        }

        $profile = $this->context->profile();
        $ownerVat = $profile->vatNumber;
        $ownerTaxCode = $profile->taxCode;
        $isOwner = fn (PartySnapshot $party): bool => ($ownerVat !== null && $party->vatNumber?->isItalian() === true && $party->vatNumber->number === $ownerVat)
            || ($ownerTaxCode !== null && $party->taxCode === $ownerTaxCode);

        if ($parsed->declaredTotal !== null && ! $parsed->declaredTotal->equals($parsed->totals->total)) {
            return ImportResult::failed($fileName, 'Il totale dichiarato ('.$parsed->declaredTotal->toDecimalString().') non corrisponde al riepilogo IVA ('.$parsed->totals->total->toDecimalString().').');
        }

        try {
            if ($isOwner($parsed->seller)) {
                return $this->importOutbound($fileName, $xml, $parsed);
            }

            if ($isOwner($parsed->customer)) {
                return $this->importInbound($fileName, $xml, $parsed);
            }
        } catch (Throwable $exception) {
            report($exception);

            return ImportResult::failed($fileName, $exception->getMessage());
        }

        return ImportResult::failed($fileName, 'Nessuna delle due parti è il profilo aziendale (partita IVA '.($ownerVat ?? '—').').');
    }

    private function importInbound(string $fileName, string $xml, FatturaPaFile $parsed): ImportResult
    {
        $seller = $parsed->seller;
        $totals = $parsed->totals;
        $date = $parsed->date;
        $number = $parsed->number;
        $fingerprint = ($seller->vatNumber?->toString() ?? $seller->taxCode ?? 'unknown').':'.$date->year.':'.$number;

        $existing = SupplierInvoice::query()->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return ImportResult::skipped($fileName, 'inbound', $existing->toData()->reference(), 'già importata');
        }

        return $this->db->transaction(function () use ($fileName, $xml, $parsed, $seller, $totals, $date, $number, $fingerprint): ImportResult {
            $supplier = $this->findOrCreateContact($seller, ContactRole::Supplier);

            $supplierInvoice = SupplierInvoice::query()->create([
                'contact_id' => $supplier->id,
                'supplier_name' => $seller->name,
                'fingerprint' => $fingerprint,
                'type' => $parsed->type,
                'number' => $number,
                'issued_on' => $date->toDateString(),
                'due_on' => $parsed->dueOn?->toDateString(),
                'currency' => $parsed->currency,
                'lines' => array_map(fn (LineInput $line): array => $line->toArray(), $parsed->lines),
                'totals' => $totals->toArray(),
                'total_cents' => $totals->total->cents,
                'file_name' => $fileName,
                'received_at' => Date::now(),
            ]);

            $file = $this->files->store($supplierInvoice->toData()->reference(), 'xml', $fileName, $xml, 'application/xml');
            $supplierInvoice->forceFill(['xml_file_id' => $file->id])->save();

            $this->audit->record('invoicing.supplier_invoice.imported', $supplierInvoice->toData()->reference(), ['number' => $number, 'supplier' => $seller->name, 'total' => $totals->total->toDecimalString()]);
            $this->events->dispatch(new SupplierInvoiceReceived($supplierInvoice->id));

            return ImportResult::imported($fileName, 'inbound', $supplierInvoice->toData()->reference());
        });
    }

    private function importOutbound(string $fileName, string $xml, FatturaPaFile $parsed): ImportResult
    {
        $customer = $parsed->customer;
        $totals = $parsed->totals;
        $date = $parsed->date;
        $numberText = $parsed->number;
        $number = self::numberFrom($numberText);
        $defaults = $this->context->defaults();
        $series = $parsed->type === DocumentType::CreditNote ? $defaults->creditNoteSeries : $defaults->invoiceSeries;

        $existing = Invoice::query()->where('series', $series)->where('year', $date->year)->where('number', $number)->first();
        if ($existing !== null) {
            return ImportResult::skipped($fileName, 'outbound', $existing->toData()->reference(), 'già presente');
        }

        return $this->db->transaction(function () use ($fileName, $xml, $parsed, $customer, $totals, $date, $numberText, $number, $series): ImportResult {
            $contact = $this->findOrCreateContact($customer, ContactRole::Client);
            $seller = $parsed->seller;

            $invoice = Invoice::query()->create([
                'type' => $parsed->type,
                'status' => InvoiceStatus::SentManually,
                'series' => $series,
                'year' => $date->year,
                'number' => $number,
                'number_text' => $numberText,
                'issued_on' => $date->toDateString(),
                'due_on' => $parsed->dueOn?->toDateString(),
                'contact_id' => $contact->id,
                'customer_name' => $customer->name,
                'currency' => $parsed->currency,
                'lines' => array_map(fn (LineInput $line): array => $line->toArray(), $parsed->lines),
                'payment_terms_days' => 0,
                'seller' => $seller->toArray(),
                'customer' => $customer->toArray(),
                'regime' => $parsed->regime,
                'totals' => $totals->toArray(),
                'total_cents' => $totals->total->cents,
                'sdi_file_name' => preg_match('/^IT[A-Z0-9]{11,16}_[A-Z0-9]{1,5}\.xml$/i', $fileName) === 1 ? $fileName : null,
                'issued_at' => Date::now(),
                'sent_at' => $date->startOfDay(),
                'sent_channel' => SendChannel::Manual,
            ]);

            $file = $this->files->store($invoice->toData()->reference(), 'xml', $fileName, $xml, 'application/xml');
            $invoice->forceFill(['xml_file_id' => $file->id])->save();
            $this->sequence->advanceTo($series, $date->year, $number);

            $this->audit->record('invoicing.invoice.imported', $invoice->toData()->reference(), ['number' => $numberText, 'customer' => $customer->name, 'total' => $totals->total->toDecimalString()]);

            return ImportResult::imported($fileName, 'outbound', $invoice->toData()->reference());
        });
    }

    private function findOrCreateContact(PartySnapshot $party, ContactRole $role): ContactData
    {
        $found = $this->contacts->byFiscalIdentity($party->vatNumber, $party->taxCode);

        if ($found !== null) {
            return $found;
        }

        $input = new ContactInput(
            kind: $party->isCompany ? ContactKind::Company : ContactKind::Individual,
            roles: [$role],
            firstName: $party->firstName,
            lastName: $party->lastName,
            businessName: $party->isCompany ? $party->name : null,
            email: $party->email,
            vatNumber: $party->vatNumber,
            taxCode: $party->taxCode,
            address: new Address($party->street, $party->streetNumber, $party->postcode, $party->city, $party->province, $party->country),
        );

        return $this->createContact->create($input);
    }

    /**
     * The number inside a Numero string: "7/2026", "2026/7", "FT-0007" → 7.
     */
    public static function numberFrom(string $numero): int
    {
        if (preg_match('#^(\d{4})[/-](\d+)$#', trim($numero), $parts) === 1) {
            return (int) $parts[2];
        }

        if (preg_match('#^(\d+)[/-](\d{4})$#', trim($numero), $parts) === 1) {
            return (int) $parts[1];
        }

        if (preg_match('/(\d+)/', $numero, $parts) === 1) {
            return (int) $parts[1];
        }

        throw new InvalidArgumentException("Il numero \"{$numero}\" non contiene cifre.");
    }
}
