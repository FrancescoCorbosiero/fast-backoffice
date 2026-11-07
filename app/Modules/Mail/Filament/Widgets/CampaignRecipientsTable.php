<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Widgets;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Mail\Actions\RequeueRecipient;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Models\CampaignRecipient;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Who a campaign went to and how it ended, each row one click from the
 * contact (a bounce or a complaint is fixed there), a failed send one
 * click from another attempt.
 */
class CampaignRecipientsTable extends TableWidget
{
    public int $campaignId = 0;

    /** A recipient status to start filtered on (from the page's badges). */
    public ?string $status = null;

    protected static bool $isDiscovered = false;

    /** @var ?array<int, ContactData> the contacts of the rows on this page */
    private ?array $contacts = null;

    public function table(Table $table): Table
    {
        $statuses = [];
        foreach (RecipientStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $table
            ->query(fn (): Builder => CampaignRecipient::query()->where('campaign_id', $this->campaignId))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('contact_name')->label('Contatto')
                    ->state(function (CampaignRecipient $record): string {
                        $contact = $this->contact($record->contact_id);

                        return $contact === null ? '—' : $contact->displayName;
                    })
                    ->url(fn (CampaignRecipient $record): string => ContactLink::url($record->contact_id))
                    ->description(fn (CampaignRecipient $record): ?string => $this->suppressionNote($record)),
                TextColumn::make('email')->label('Email')->searchable()
                    ->url(fn (CampaignRecipient $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (RecipientStatus $state): string => $state->label())->color(fn (RecipientStatus $state): string => $state->color()),
                TextColumn::make('attempts')->label('Tentativi')->alignEnd(),
                TextColumn::make('sent_at')->label('Inviata')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_error')->label('Note')->limit(60)->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label('Stato')->options($statuses)->default($this->status)])
            ->recordActions([
                Action::make('contact')->label('Apri contatto')->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (CampaignRecipient $record): string => ContactLink::url($record->contact_id)),
                Action::make('requeue')->label('Rimetti in coda')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->visible(fn (CampaignRecipient $record): bool => $record->status === RecipientStatus::Failed)
                    ->requiresConfirmation()
                    ->modalDescription('L\'invio viene ritentato da capo, al ritmo impostato.')
                    ->action(function (CampaignRecipient $record): void {
                        $this->requeue([$record]);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('requeue')->label('Rimetti in coda le fallite')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Solo le righe fallite vengono ritentate; le altre restano come sono.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $this->requeue(array_values($records->all()));
                    }),
            ])
            ->paginated([25, 50]);
    }

    /**
     * @param  list<mixed>  $records
     */
    private function requeue(array $records): void
    {
        $done = 0;
        foreach ($records as $record) {
            if (! $record instanceof CampaignRecipient || $record->status !== RecipientStatus::Failed) {
                continue;
            }
            try {
                app(RequeueRecipient::class)->requeue($record->id);
                $done++;
            } catch (LogicException $exception) {
                Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

                return;
            }
        }

        Notification::make()->title($done === 0 ? 'Nessuna riga fallita tra quelle scelte' : ($done === 1 ? '1 destinatario rimesso in coda' : $done.' destinatari rimessi in coda'))->success()->send();
    }

    private function suppressionNote(CampaignRecipient $record): ?string
    {
        $contact = $this->contact($record->contact_id);
        if ($contact === null) {
            return 'contatto non più attivo';
        }
        if ($contact->isEmailSuppressed()) {
            return 'email bloccata'.($contact->emailSuppressedAt === null ? '' : ' il '.$contact->emailSuppressedAt->isoFormat('D MMM YYYY'));
        }
        if (! $contact->emailConsent) {
            return 'consenso revocato';
        }

        return null;
    }

    /**
     * The contacts behind the rows on this page, read once per render.
     */
    private function contact(int $contactId): ?ContactData
    {
        if ($this->contacts === null) {
            $records = $this->getTableRecords();
            $rows = $records instanceof Paginator || $records instanceof CursorPaginator ? $records->items() : $records->all();
            $ids = [];
            foreach ($rows as $row) {
                if ($row instanceof CampaignRecipient) {
                    $ids[] = $row->contact_id;
                }
            }
            $this->contacts = app(FindContact::class)->byIds(array_values(array_unique($ids)));
        }

        return $this->contacts[$contactId] ?? null;
    }
}
