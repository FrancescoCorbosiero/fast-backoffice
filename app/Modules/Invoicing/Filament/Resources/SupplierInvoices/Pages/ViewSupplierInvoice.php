<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages;

use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Filament\DocumentInfolist;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Models\SupplierInvoice;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewSupplierInvoice extends ViewRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof SupplierInvoice ? $record->toData()->title() : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $invoice = $this->supplierInvoice();
        $data = $invoice->toData();

        return $schema->components([
            Section::make()->columns(4)->components([
                TextEntry::make('h_number')->label('Numero')->state($data->number),
                TextEntry::make('h_date')->label('Data')->state($data->issuedOn->isoFormat('D MMM YYYY')),
                TextEntry::make('h_due')->label('Scadenza')->state($data->dueOn?->isoFormat('D MMM YYYY') ?? '—'),
                TextEntry::make('h_paid')->label('Pagato')->state($data->paid->format().' su '.$data->total->format()),
            ]),
            Section::make('Fornitore')->compact()->components([
                TextEntry::make('h_supplier')->label('')->state($data->supplierName),
            ]),
            DocumentInfolist::lines($data->lines),
            DocumentInfolist::totals($data->totals),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('xml')->label('Scarica XML')->icon(Heroicon::OutlinedCodeBracket)
                ->visible(fn (): bool => $this->supplierInvoice()->xml_file_id !== null)
                ->action(function (): StreamedResponse {
                    $invoice = $this->supplierInvoice();
                    $contents = app(ReadFile::class)->contents($invoice->xml_file_id ?? 0);

                    return response()->streamDownload(fn () => print $contents, $invoice->file_name ?? 'fattura.xml', ['Content-Type' => 'application/xml']);
                }),
        ];
    }

    private function supplierInvoice(): SupplierInvoice
    {
        $record = $this->getRecord();

        if (! $record instanceof SupplierInvoice) {
            throw new LogicException('The page shows supplier invoices.');
        }

        return $record;
    }
}
