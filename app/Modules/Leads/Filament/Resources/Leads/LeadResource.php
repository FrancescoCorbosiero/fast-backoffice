<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Filament\Resources\Leads\Pages\CreateLead;
use App\Modules\Leads\Filament\Resources\Leads\Pages\EditLead;
use App\Modules\Leads\Filament\Resources\Leads\Pages\ListLeads;
use App\Modules\Leads\Filament\Resources\Leads\Pages\ViewLead;
use App\Modules\Leads\Models\Lead;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Crescita';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'lead';

    protected static ?string $pluralModelLabel = 'Lead';

    protected static ?string $slug = 'leads';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(120),
                TextInput::make('company')->label('Azienda')->maxLength(160),
                TextInput::make('email')->label('Email')->email()->maxLength(190),
                TextInput::make('phone')->label('Telefono')->maxLength(40),
                TextInput::make('source')->label('Fonte')->maxLength(60)->placeholder('passaparola, sito, fiera…'),
                Amounts::input('estimated_value', 'Valore stimato')->required(false),
                DatePicker::make('next_follow_up_on')->label('Prossimo contatto'),
            ]),
            Textarea::make('notes')->label('Note')->rows(3)->maxLength(4000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = Date::today();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->description(fn (Lead $record): ?string => $record->company),
                TextColumn::make('email')->label('Email')->placeholder('—')->searchable(),
                TextColumn::make('source')->label('Fonte')->placeholder('—'),
                TextColumn::make('estimated_value_cents')->label('Valore')->alignEnd()->placeholder('—')->formatStateUsing(fn (Lead $record): string => $record->toData()->estimatedValue?->format() ?? '—'),
                TextColumn::make('next_follow_up_on')->label('Prossimo contatto')->date('d/m/Y')->placeholder('—')->sortable()
                    ->color(fn (Lead $record): ?string => $record->toData()->isFollowUpDue($today) ? 'danger' : null),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (LeadStatus $state): string => $state->label())->color(fn (LeadStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(LeadStatus::options())->default(null),
                Filter::make('open')->label('Solo aperti')->default()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', array_map(fn (LeadStatus $s): string => $s->value, LeadStatus::open()))),
                Filter::make('due')->label('Da ricontattare')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', array_map(fn (LeadStatus $s): string => $s->value, LeadStatus::open()))->where('next_follow_up_on', '<=', $today->toDateString())),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Lead && $record->status !== LeadStatus::Won;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
