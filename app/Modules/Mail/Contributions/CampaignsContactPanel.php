<?php

declare(strict_types=1);

namespace App\Modules\Mail\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Widgets\ContactCampaignsTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class CampaignsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'campaigns';
    }

    public function label(): string
    {
        return 'Campagne email';
    }

    public function sort(): int
    {
        return 60;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactCampaignsTable::class, ['contactId' => $contactId]);
    }

    /**
     * Nothing to create for one contact: a campaign goes to every mailable
     * contact with a role in its audience, so it is written from the
     * campaigns list, not from here. The section still leads somewhere — its
     * header and empty state open the campaigns and start a new one.
     */
    public function createAction(int $contactId): ?Action
    {
        return null;
    }

    /**
     * Every campaign, this contact's included. Built fresh on every call:
     * an Action instance belongs to one Livewire component.
     */
    public static function openCampaigns(): Action
    {
        return Action::make('open-campaigns')
            ->label('Vai alle campagne')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('gray')
            ->url(CampaignResource::getUrl('index'));
    }

    /**
     * Write a campaign: it reaches this contact too, when its role is in
     * the audience and it is mailable. Built fresh on every call.
     */
    public static function newCampaign(): Action
    {
        return Action::make('new-campaign')
            ->label('Nuova campagna')
            ->icon(Heroicon::OutlinedPlus)
            ->url(CampaignResource::getUrl('create'));
    }

    /**
     * Why this contact has received no campaign yet, in one sentence: it
     * will get the next one aimed at its role, or what stops it from being
     * mailed (no address, no consent, a blocked address).
     */
    public static function emptyStateDescription(int $contactId): string
    {
        $contact = app(FindContact::class)->byId($contactId);

        if ($contact === null) {
            return 'Il contatto non è più attivo: non riceve campagne.';
        }

        if ($contact->email === null) {
            return 'Senza un indirizzo email non può ricevere campagne: aggiungilo al contatto.';
        }

        if ($contact->isEmailSuppressed()) {
            return 'L\'indirizzo è bloccato ('.self::suppressionReason($contact->emailSuppressionReason).'): nessuna campagna lo raggiunge finché non lo sblocchi.';
        }

        if (! $contact->emailConsent) {
            return 'Non ha dato il consenso alle email di marketing: nessuna campagna lo raggiunge finché non lo registri.';
        }

        return 'Riceverà la prossima campagna rivolta ai contatti con ruolo '.self::roles($contact).'.';
    }

    private static function roles(ContactData $contact): string
    {
        $labels = array_map(fn (ContactRole $role): string => $role->label(), $contact->roles);

        return $labels === [] ? ContactRole::Other->label() : implode(' o ', $labels);
    }

    private static function suppressionReason(?string $reason): string
    {
        return match ($reason) {
            'bounce' => 'email respinta',
            'complaint' => 'segnalata come spam',
            'unsubscribe' => 'si è disiscritto',
            'manual' => 'bloccato a mano',
            null, '' => 'motivo non indicato',
            default => $reason,
        };
    }
}
