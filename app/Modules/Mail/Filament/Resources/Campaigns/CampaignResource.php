<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Modules\Mail\Models\Campaign;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Crescita';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'campagna';

    protected static ?string $pluralModelLabel = 'Campagne email';

    protected static ?string $slug = 'campaigns';

    public static function form(Schema $schema): Schema
    {
        $roles = [];
        foreach (ContactRole::cases() as $role) {
            $roles[$role->value] = $role->label();
        }

        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome interno')->required()->maxLength(160),
                TextInput::make('subject')->label('Oggetto')->required()->maxLength(200),
                TextInput::make('preheader')->label('Anteprima (preheader)')->maxLength(200)->columnSpanFull(),
                CheckboxList::make('audience_roles')->label('Destinatari')->options($roles)->columns(4)->columnSpanFull()
                    ->helperText('Nessuna spunta: tutti i contatti con email e consenso. Chi ha revocato il consenso o ha un indirizzo bloccato non riceve mai nulla.'),
            ]),
            RichEditor::make('body_html')->label('Contenuto')->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->description(fn (Campaign $record): string => $record->subject),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (CampaignStatus $state): string => $state->label())->color(fn (CampaignStatus $state): string => $state->color()),
                TextColumn::make('scheduled_at')->label('Programmata')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('started_at')->label('Avviata')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('recipients_count')->label('Destinatari')->counts('recipients')->alignEnd(),
            ])
            ->filters([SelectFilter::make('status')->label('Stato')->options(CampaignStatus::options())])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Campaign && $record->status->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
