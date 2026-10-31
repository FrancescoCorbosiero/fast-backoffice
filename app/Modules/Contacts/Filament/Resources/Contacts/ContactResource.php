<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts;

use App\Modules\Contacts\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Contacts\Filament\Resources\Contacts\Pages\ViewContact;
use App\Modules\Contacts\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Modules\Contacts\Filament\Resources\Contacts\Schemas\ContactInfolist;
use App\Modules\Contacts\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Modules\Contacts\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'contatto';

    protected static ?string $pluralModelLabel = 'Contatti';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getEloquentQuery(): Builder
    {
        // Merged duplicates never show up in the panel.
        return parent::getEloquentQuery()->whereNull('merged_into_id');
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function canDelete(Model $record): bool
    {
        // A contact is never deleted: other modules' documents point at it. Merge duplicates instead.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
