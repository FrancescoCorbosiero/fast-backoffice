<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Schemas;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The contact's own data on its page. Email, phone and PEC are links
 * (write, call); the fiscal section says whether an invoice can be issued
 * to the contact yet, and offers to complete the data when it cannot.
 */
class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->components([
                TextEntry::make('display_name')->label('Nome')->weight('bold')->columnSpan(2),
                TextEntry::make('kind')->label('Tipo')->formatStateUsing(fn (Contact $record): string => $record->kind->label()),
                TextEntry::make('roles')->label('Ruoli')->badge()
                    ->state(fn (Contact $record): array => $record->roleEnums())
                    ->formatStateUsing(fn (ContactRole $state): string => $state->label())
                    ->color(fn (ContactRole $state): string => $state->color()),
                TextEntry::make('email')->label('Email')->placeholder('—')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->url(fn (Contact $record): ?string => $record->email !== null ? 'mailto:'.$record->email : null),
                TextEntry::make('phone')->label('Telefono')->placeholder('—')
                    ->icon(Heroicon::OutlinedPhone)
                    ->url(fn (Contact $record): ?string => $record->phone !== null ? 'tel:'.preg_replace('/[^+\d]/', '', $record->phone) : null),
                TextEntry::make('pec')->label('PEC')->placeholder('—')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->url(fn (Contact $record): ?string => $record->pec !== null ? 'mailto:'.$record->pec : null),
                TextEntry::make('email_state')->label('Stato email')->badge()
                    ->state(fn (Contact $record): string => match (true) {
                        $record->email_suppressed_at !== null => 'bloccata: '.$record->email_suppression_reason,
                        $record->email_consent => 'consenso registrato',
                        default => 'senza consenso',
                    })
                    ->color(fn (Contact $record): string => match (true) {
                        $record->email_suppressed_at !== null => 'danger',
                        $record->email_consent => 'success',
                        default => 'gray',
                    }),
            ]),
            Section::make('Identità fiscale')->columns(4)->components([
                TextEntry::make('fiscal_state')->label('Fatturazione')->badge()->columnSpanFull()
                    ->state(fn (Contact $record): string => self::fiscalState($record))
                    ->color(fn (Contact $record): string => $record->toData()->hasFiscalIdentity() ? 'success' : 'warning')
                    ->hintAction(
                        Action::make('complete')
                            ->label('Completa')
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->url(fn (Contact $record): string => ContactLink::editUrl($record->id))
                            ->visible(fn (Contact $record): bool => ! $record->toData()->hasFiscalIdentity()),
                    ),
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

    private static function fiscalState(Contact $record): string
    {
        $data = $record->toData();

        return match (true) {
            $data->hasFiscalIdentity() => 'Fatturabile: '.self::routing($record),
            $data->vatNumber === null && $data->taxCode === null => 'Manca partita IVA o codice fiscale: non fatturabile',
            default => 'Indirizzo incompleto: non fatturabile',
        };
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
