<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditEntries\Tables;

use App\Modules\Audit\Models\AuditEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('action')->label('Azione')->searchable(),
                TextColumn::make('subject')->label('Oggetto')->searchable()->placeholder('—'),
                TextColumn::make('details')
                    ->label('Dettagli')
                    ->state(fn (AuditEntry $record): string => self::summarise($record->details))
                    ->wrap(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function summarise(array $details): string
    {
        $parts = [];
        foreach ($details as $key => $value) {
            $parts[] = $key.': '.(is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return implode(', ', $parts);
    }
}
