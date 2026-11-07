<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

/**
 * A section of the contact page contributed by a higher module (their
 * invoices, quotes, websites…). Register it in the module's provider:
 *
 *     $this->app->tag([InvoicesPanel::class], ContactPanel::TAG);
 *
 * The page is a journey, not a list of tables: every section says what
 * the owner can do next. A section that lists things the owner creates
 * offers to create one for this contact, both in its own header and in
 * its empty state, and through createAction() in the page's "Nuovo" menu.
 */
interface ContactPanel
{
    public const string TAG = 'contacts.panels';

    public function key(): string;

    public function label(): string;

    /**
     * Where the section goes on the page; lower first.
     */
    public function sort(): int;

    /**
     * The whole section for one contact, heading included: a schema
     * component, usually a Livewire table widget whose heading is label()
     * and whose header and empty state carry the create action.
     */
    public function component(int $contactId): Component;

    /**
     * How the owner adds one of these for the contact — a link to a form
     * prefilled for them, or an action with its own modal — shown in the
     * page's "Nuovo" menu. Null when these are not created by hand
     * (bookings come from Cal.com, campaigns are sent to many).
     */
    public function createAction(int $contactId): ?Action;
}
