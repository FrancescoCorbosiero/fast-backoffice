<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Actions\SaveMailSettings;
use App\Modules\Settings\Data\MailSettings;
use App\Modules\Settings\Queries\GetMailSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MailSettingsPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Campagne email';

    protected static ?string $title = 'Campagne email';

    protected static ?string $slug = 'settings/mail';

    protected static ?int $navigationSort = 5;

    public function form(Schema $schema): Schema
    {
        $mailer = config('mail-campaigns.mailer');
        $region = config('services.ses.region');
        $transport = sprintf('Invio attraverso il mailer "%s" (regione SES: %s). Chiavi AWS e mailer si impostano nelle variabili d\'ambiente, mai qui.', is_string($mailer) ? $mailer : 'ses', is_string($region) ? $region : '—');

        return parent::form($schema)->components([
            Section::make('Mittente')->columns(3)->components([
                TextInput::make('from_address')->label('Indirizzo mittente')->email()->maxLength(190)->helperText('Verificato su SES.'),
                TextInput::make('from_name')->label('Nome mittente')->maxLength(120),
                TextInput::make('reply_to')->label('Risposte a')->email()->maxLength(190),
                Text::make($transport)->columnSpanFull(),
            ]),
            Section::make('Ritmo e tentativi')->columns(3)->components([
                TextInput::make('rate_per_second')->label('Email al secondo')->integer()->minValue(1)->maxValue(50)->required()->helperText('Sotto il limite del tuo account SES.'),
                TextInput::make('retry_hours')->label('Riprova per (ore)')->integer()->minValue(1)->maxValue(168)->required()->helperText('Un invio che fallisce viene ritentato finché non passa questo tempo.'),
                TextInput::make('transient_bounces_before_suppression')->label('Bounce temporanei prima del blocco')->integer()->minValue(1)->maxValue(10)->required(),
            ]),
        ]);
    }

    protected function load(): array
    {
        return app(GetMailSettings::class)->get()->toArray();
    }

    protected function persist(array $state): void
    {
        app(SaveMailSettings::class)->save(MailSettings::fromArray($state));
    }
}
