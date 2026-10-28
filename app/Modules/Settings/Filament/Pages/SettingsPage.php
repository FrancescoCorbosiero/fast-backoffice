<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use LogicException;
use UnitEnum;

/**
 * A settings screen: one form, filled from a Query, saved through an Action.
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Impostazioni';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @return array<string, mixed>
     */
    abstract protected function load(): array;

    /**
     * @param  array<string, mixed>  $state
     */
    abstract protected function persist(array $state): void;

    public function mount(): void
    {
        $this->form->fill($this->load());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Salva')->submit('save')->keyBindings(['mod+s']),
                    ])->alignment(Alignment::Start)->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function save(): void
    {
        try {
            $this->persist($this->form->getState());
        } catch (LogicException $exception) {
            Notification::make()->title('Impossibile salvare')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Impostazioni salvate')->success()->send();
    }
}
