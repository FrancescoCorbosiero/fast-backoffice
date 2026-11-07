<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Pages;

use App\Modules\Contacts\Actions\MergeContacts;
use App\Modules\Contacts\Actions\SetEmailConsent;
use App\Modules\Contacts\Actions\SuppressEmail;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use LogicException;

/**
 * The contact page: the contact's own data, then the sections higher
 * modules contribute through the ContactPanel extension point. The
 * "Nuovo" menu in the header gathers every section's create action, so
 * the next step (a quote, a site, an invoice…) is one click away.
 */
class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->contact()->display_name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getInfolistContentComponent(),
            ...$this->panelSections(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $create = $this->createActions();

        return [
            ActionGroup::make($create)
                ->label('Nuovo')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->button()
                ->visible($create !== []),
            EditAction::make(),
            ActionGroup::make([
                Action::make('suppress')
                    ->label('Blocca email')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (): bool => $this->contact()->email_suppressed_at === null && $this->contact()->email !== null)
                    ->schema([TextInput::make('reason')->label('Motivo')->required()->maxLength(100)->default('richiesta del contatto')])
                    ->action(function (array $data): void {
                        app(SuppressEmail::class)->suppress($this->contact()->id, is_string($data['reason'] ?? null) ? $data['reason'] : 'manual');
                        $this->refreshRecord('Email bloccata');
                    }),
                Action::make('lift')
                    ->label('Sblocca email')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (): bool => $this->contact()->email_suppressed_at !== null)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(SuppressEmail::class)->lift($this->contact()->id);
                        $this->refreshRecord('Email sbloccata');
                    }),
                Action::make('consent')
                    ->label(fn (): string => $this->contact()->email_consent ? 'Revoca consenso email' : 'Registra consenso email')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(SetEmailConsent::class)->set($this->contact()->id, ! $this->contact()->email_consent, 'owner');
                        $this->refreshRecord('Consenso aggiornato');
                    }),
                Action::make('merge')
                    ->label('Unisci un duplicato')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->modalDescription('Il contatto scelto viene unito a questo: i suoi dati riempiono i campi vuoti, i documenti passano a questo contatto, e il duplicato sparisce dagli elenchi.')
                    ->schema([ContactPicker::make('duplicate_id', [])->label('Duplicato da unire')->required()])
                    ->action(function (array $data): void {
                        $duplicateId = is_numeric($data['duplicate_id'] ?? null) ? (int) $data['duplicate_id'] : 0;

                        if ($duplicateId === $this->contact()->id) {
                            Notification::make()->title('Scegli un contatto diverso da questo')->danger()->send();

                            return;
                        }

                        app(MergeContacts::class)->merge($this->contact()->id, $duplicateId);
                        $this->refreshRecord('Contatti uniti');
                    }),
            ])->label('Altro')->icon(Heroicon::OutlinedEllipsisHorizontal)->button()->color('gray'),
        ];
    }

    /**
     * @return list<Component>
     */
    private function panelSections(): array
    {
        return array_map(
            fn (ContactPanel $panel): Component => $panel->component($this->contact()->id)->key('panel-'.$panel->key()),
            $this->panels(),
        );
    }

    /**
     * The "Nuovo" menu: one entry per section that creates things, in page order.
     *
     * @return list<Action>
     */
    private function createActions(): array
    {
        $actions = [];

        foreach ($this->panels() as $panel) {
            $action = $panel->createAction($this->contact()->id);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return list<ContactPanel>
     */
    private function panels(): array
    {
        /** @var iterable<ContactPanel> $panels */
        $panels = app()->tagged(ContactPanel::TAG);
        $sorted = [];

        foreach ($panels as $panel) {
            $sorted[] = $panel;
        }

        usort($sorted, fn (ContactPanel $a, ContactPanel $b): int => $a->sort() <=> $b->sort());

        return $sorted;
    }

    private function contact(): Contact
    {
        $record = $this->getRecord();

        if (! $record instanceof Contact) {
            throw new LogicException('The contact page shows contacts.');
        }

        return $record;
    }

    private function refreshRecord(string $message): void
    {
        $this->record = $this->contact()->refresh();
        Notification::make()->title($message)->success()->send();
    }
}
