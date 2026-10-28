<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Schemas;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->components([
                TextEntry::make('display_name')->label('Nome')->weight('bold')->columnSpan(2),
                TextEntry::make('kind')->label('Tipo')->formatStateUsing(fn (Contact $record): string => $record->kind->label()),
                TextEntry::make('roles')->label('Ruoli')->badge()
                    ->state(fn (Contact $record): array => array_map(fn (ContactRole $role): string => $role->label(), $record->roleEnums())),
                TextEntry::make('email')->label('Email')->copyable()->placeholder('—')
                    ->suffix(fn (Contact $record): ?string => $record->email_suppressed_at !== null ? ' (bloccata: '.$record->email_suppression_reason.')' : null),
                TextEntry::make('phone')->label('Telefono')->placeholder('—'),
                TextEntry::make('email_consent')->label('Consenso email')->formatStateUsing(fn (bool $state): string => $state ? 'sì' : 'no'),
                TextEntry::make('pec')->label('PEC')->placeholder('—'),
            ]),
            Section::make('Identità fiscale')->columns(4)->components([
                TextEntry::make('vat_number')->label('Partita IVA')->placeholder('—')
                    ->formatStateUsing(fn (Contact $record): string => ($record->vat_country ?? 'IT').' '.$record->vat_number),
                TextEntry::make('tax_code')->label('Codice fiscale')->placeholder('—'),
                TextEntry::make('sdi_code')->label('Codice destinatario')->placeholder('—'),
                TextEntry::make('sdi_routing')->label('Recapito SdI')
                    ->state(fn (Contact $record): string => self::routing($record)),
                TextEntry::make('address')->label('Indirizzo')->columnSpanFull()
                    ->state(fn (Contact $record): string => $record->toData()->address->oneLine())->placeholder('—'),
            ]),
            Section::make('Note')->components([
                TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
            ])->visible(fn (Contact $record): bool => $record->notes !== null),
        ]);
    }

    private static function routing(Contact $record): string
    {
        $address = $record->toData()->sdiAddress();

        return match (true) {
            $address->isAbroad() => 'estero (XXXXXXX)',
            $address->pec !== null => 'PEC '.$address->pec,
            $address->isPortalOnly() => 'portale Agenzia delle Entrate (0000000)',
            default => 'codice '.$address->codiceDestinatario,
        };
    }
}
