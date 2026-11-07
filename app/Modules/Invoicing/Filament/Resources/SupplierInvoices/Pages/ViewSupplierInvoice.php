<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\PaySupplierInvoice;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Filament\Components\DocumentInfolist;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Models\SupplierInvoice;
use App\Modules\Invoicing\Queries\InvoicePayments;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
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
                TextEntry::make('h_supplier')->label('')->state($data->supplierName)->url(ContactLink::url($invoice->contact_id)),
            ]),
            DocumentInfolist::lines($data->lines),
            DocumentInfolist::totals($data->totals),
            $this->paymentsSection(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payment')
                ->label('Registra pagamento')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('primary')
                ->visible(fn (): bool => $this->supplierInvoice()->toData()->outstanding()->isPositive())
                ->fillForm(fn (): array => ['amount' => $this->supplierInvoice()->toData()->outstanding()->toDecimalString(), 'paid_on' => today()->toDateString()])
                ->schema([
                    TextInput::make('amount')->label('Importo')->prefix('€')->required(),
                    DatePicker::make('paid_on')->label('Pagata il')->required(),
                    Select::make('account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(PaySupplierInvoice::class)->pay($this->supplierInvoice()->id, PaymentInput::fromArray($data));
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Pagamento non registrato')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

                        return;
                    }

                    $this->record = $this->supplierInvoice()->refresh();
                    Notification::make()->title('Pagamento registrato')->success()->send();
                }),
            Action::make('xml')->label('Scarica XML')->icon(Heroicon::OutlinedCodeBracket)
                ->visible(fn (): bool => $this->supplierInvoice()->xml_file_id !== null)
                ->action(function (): StreamedResponse {
                    $invoice = $this->supplierInvoice();
                    $contents = app(ReadFile::class)->contents($invoice->xml_file_id ?? 0);

                    return response()->streamDownload(fn () => print $contents, $invoice->file_name ?? 'fattura.xml', ['Content-Type' => 'application/xml']);
                }),
        ];
    }

    /**
     * The outgoing movements allocated to this payable, and the account each left.
     */
    private function paymentsSection(): Section
    {
        $payments = app(InvoicePayments::class)->for($this->supplierInvoice()->toData()->reference());
        $accounts = app(Accounts::class)->options(activeOnly: false);
        $entries = [];

        foreach ($payments as $payment) {
            $entries[] = TextEntry::make('p_'.$payment->movementId)->label('')
                ->state($payment->paidOn->isoFormat('D MMM YYYY').' · '.$payment->amount->abs()->format().' · '.$payment->description.' · dal conto '.($accounts[$payment->accountId] ?? '—'));
        }

        if ($entries === []) {
            $entries[] = TextEntry::make('p_none')->label('')->state('Nessun pagamento registrato.');
        }

        return Section::make('Pagamenti')->compact()->components($entries);
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
