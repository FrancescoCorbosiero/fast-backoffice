<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Actions\SaveSdiSettings;
use App\Modules\Settings\Data\SdiSettings;
use App\Modules\Settings\Queries\GetSdiSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SdiSettingsPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'SdI e PEC';

    protected static ?string $title = 'Sistema di Interscambio via PEC';

    protected static ?string $slug = 'settings/sdi';

    protected static ?int $navigationSort = 4;

    public function form(Schema $schema): Schema
    {
        $smtp = config('pec.smtp.host');
        $imap = config('pec.imap.host');
        $transport = sprintf(
            'Casella configurata sul server: SMTP %s · IMAP %s. Credenziali e host si impostano nelle variabili d\'ambiente PEC_* (mai qui).',
            is_string($smtp) && $smtp !== '' ? $smtp : 'non configurato',
            is_string($imap) && $imap !== '' ? $imap : 'non configurato',
        );

        return parent::form($schema)->components([
            Section::make('Indirizzi')->columns(2)->components([
                TextInput::make('pec_address')->label('PEC dell\'app')->email()->maxLength(190)->helperText('La casella PEC usata solo dall\'app, da cui partono le fatture.'),
                TextInput::make('sdi_address')->label('Indirizzo di SdI')->email()->required()->maxLength(190)
                    ->helperText('Il primo invio va a '.SdiSettings::FIRST_SDI_ADDRESS.'; SdI risponde dall\'indirizzo da usare in seguito e l\'app lo salva qui (sdi.md §2).'),
                Text::make($transport)->columnSpanFull(),
            ]),
            Section::make('Interruttori')->columns(2)->components([
                Toggle::make('auto_send')->label('Invia automaticamente le fatture emesse a SdI')->helperText('Spento: "Scarica XML" e "Segna come inviata" restano sempre disponibili.'),
                Toggle::make('inbox_enabled')->label('Leggi la casella PEC (ricevute, notifiche, fatture fornitori)'),
            ]),
        ]);
    }

    protected function load(): array
    {
        return app(GetSdiSettings::class)->get()->toArray();
    }

    protected function persist(array $state): void
    {
        app(SaveSdiSettings::class)->save(SdiSettings::fromArray($state));
    }
}
