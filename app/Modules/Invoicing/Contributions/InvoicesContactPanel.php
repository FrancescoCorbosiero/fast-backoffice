<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Modules\Invoicing\Filament\Widgets\ContactInvoicesTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class InvoicesContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Fatture';
    }

    public function sort(): int
    {
        return 20;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactInvoicesTable::class, ['contactId' => $contactId]);
    }

    /**
     * One entry per panel in the page's "Nuovo" menu: the invoice. The
     * payment request stays in the section's own header.
     */
    public function createAction(int $contactId): Action
    {
        return self::newInvoice($contactId);
    }

    /**
     * The one way to bill a contact: a draft on the create form, already
     * knowing the client. Built fresh on every call: an Action instance
     * belongs to one Livewire component.
     */
    public static function newInvoice(int $contactId): Action
    {
        return Action::make('new-invoice')
            ->label('Nuova fattura')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->url(CreateInvoice::prefilledUrl(['contact_id' => $contactId]));
    }

    /**
     * A payment request (acconto) for the contact, before any fattura
     * exists; it converts into one once paid.
     */
    public static function newPaymentRequest(int $contactId): Action
    {
        return Action::make('new-payment-request')
            ->label('Nuova richiesta di pagamento')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->url(CreatePaymentRequest::prefilledUrl(['contact_id' => $contactId]));
    }
}
