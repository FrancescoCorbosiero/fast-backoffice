<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Categories;

use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Filament\Resources\Categories\Pages\ManageCategories;
use App\Modules\Cash\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?int $navigationSort = 31;

    protected static ?string $modelLabel = 'categoria';

    protected static ?string $pluralModelLabel = 'Categorie';

    protected static ?string $slug = 'cash/categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(60),
            Select::make('kind')->label('Per')->options(CategoryKind::options())->required(),
            Toggle::make('is_active')->label('Attiva')->default(true),
            TextInput::make('sort')->label('Ordine')->integer()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('kind')->label('Per')->badge()->formatStateUsing(fn (CategoryKind $state): string => $state->label())
                    ->color(fn (CategoryKind $state): string => $state->direction()->color()),
                IconColumn::make('is_active')->label('Attiva')->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Per')->options(CategoryKind::options()),
            ])
            ->recordActions([EditAction::make()])
            ->paginated(false);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
