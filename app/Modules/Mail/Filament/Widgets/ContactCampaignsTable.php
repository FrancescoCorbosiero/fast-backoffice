<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Widgets;

use App\Modules\Contacts\Actions\SetEmailConsent;
use App\Modules\Contacts\Actions\SuppressEmail;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Mail\Contributions\CampaignsContactPanel;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Models\CampaignRecipient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a contact received, and first of all whether the next campaign
 * will reach it: the answer is the heading's description, and when it is
 * "no" the fix (lift the block, record the consent) sits right there.
 */
class ContactCampaignsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CampaignRecipient::query()->with('campaign')->where('contact_id', $this->contactId))
            ->heading('Campagne email')
            ->description(fn (): string => CampaignsContactPanel::mailabilityDescription($this->contactId))
            ->headerActions([$this->unblockEmail(), $this->recordConsent(), CampaignsContactPanel::openCampaigns(), CampaignsContactPanel::newCampaign()])
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('campaign.name')->label('Campagna')->url(fn (CampaignRecipient $record): string => CampaignResource::getUrl('view', ['record' => $record->campaign_id])),
                TextColumn::make('sent_at')->label('Inviata')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (RecipientStatus $state): string => $state->label())->color(fn (RecipientStatus $state): string => $state->color()),
                TextColumn::make('unsubscribed_at')->label('Disiscritto')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedMegaphone)
            ->emptyStateHeading('Nessuna campagna ricevuta')
            ->emptyStateDescription(fn (): string => CampaignsContactPanel::mailabilityDescription($this->contactId))
            ->emptyStateActions([$this->unblockEmail(), $this->recordConsent(), CampaignsContactPanel::openCampaigns(), CampaignsContactPanel::newCampaign()])
            ->paginated([10, 25]);
    }

    private function unblockEmail(): Action
    {
        return Action::make('lift')->label('Sblocca email')->icon(Heroicon::OutlinedCheckCircle)->color('warning')
            ->visible(fn (): bool => $this->contact()?->isEmailSuppressed() ?? false)
            ->requiresConfirmation()
            ->modalDescription('Da ora le campagne tornano a raggiungere questo indirizzo. Fallo solo se sai che funziona.')
            ->action(function (): void {
                app(SuppressEmail::class)->lift($this->contactId);
                Notification::make()->title('Email sbloccata')->success()->send();
            });
    }

    private function recordConsent(): Action
    {
        return Action::make('consent')->label('Registra consenso email')->icon(Heroicon::OutlinedEnvelope)->color('warning')
            ->visible(function (): bool {
                $contact = $this->contact();

                return $contact !== null && $contact->email !== null && ! $contact->emailConsent && ! $contact->isEmailSuppressed();
            })
            ->requiresConfirmation()
            ->modalDescription('Registra che il contatto ha accettato di ricevere le email dello studio.')
            ->action(function (): void {
                app(SetEmailConsent::class)->set($this->contactId, true, 'owner');
                Notification::make()->title('Consenso registrato')->success()->send();
            });
    }

    private function contact(): ?ContactData
    {
        return app(FindContact::class)->byId($this->contactId);
    }
}
