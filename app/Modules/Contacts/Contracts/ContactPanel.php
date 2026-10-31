<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use Filament\Schemas\Components\Component;

/**
 * A section of the contact page contributed by a higher module (their
 * invoices, quotes, websites…). Register it in the module's provider:
 *
 *     $this->app->tag([InvoicesPanel::class], ContactPanel::TAG);
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
     * The section's content for one contact: a schema component, which may
     * embed a Livewire component for tables.
     */
    public function component(int $contactId): Component;
}
