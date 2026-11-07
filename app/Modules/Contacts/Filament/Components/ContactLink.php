<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Components;

use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;

/**
 * Where a contact's page is, for every other module's screens: a
 * customer's name on an invoice, the owner of a site or a domain, the
 * recipient of a campaign are all one click from the contact.
 */
final class ContactLink
{
    public static function url(int $contactId): string
    {
        return ContactResource::getUrl('view', ['record' => $contactId]);
    }

    public static function createUrl(): string
    {
        return ContactResource::getUrl('create');
    }

    /**
     * The contact's edit form; with $returnTo (a URL of this app), saving
     * sends the owner back there instead of to the contact page. For
     * "Completa i dati del cliente" links from a draft invoice or quote.
     */
    public static function editUrl(int $contactId, ?string $returnTo = null): string
    {
        return ContactResource::getUrl('edit', array_filter(['record' => $contactId, 'return' => $returnTo], fn (mixed $value): bool => $value !== null));
    }
}
