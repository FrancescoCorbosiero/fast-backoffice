<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Widgets;

use App\Modules\Repositories\Contributions\RepositoriesWebsitePanel;
use App\Modules\Repositories\Models\Repository;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class WebsiteRepositoriesTable extends TableWidget
{
    public int $websiteId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Repository::query()->where('website_id', $this->websiteId))
            ->heading('Repository')
            ->headerActions([RepositoriesWebsitePanel::newRepository($this->websiteId)])
            ->defaultSort('name')
            ->columns(ContactRepositoriesTable::columns())
            ->emptyStateIcon(Heroicon::OutlinedCodeBracket)
            ->emptyStateHeading('Nessun repository')
            ->emptyStateDescription('La pipeline lavora sul repository collegato a questo sito.')
            ->emptyStateActions([RepositoriesWebsitePanel::newRepository($this->websiteId)])
            ->paginated([10, 25]);
    }
}
