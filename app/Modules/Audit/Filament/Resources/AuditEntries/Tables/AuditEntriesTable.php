<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditEntries\Tables;

use App\Kernel\Reference;
use App\Modules\Audit\Models\AuditEntry;
use App\Support\Shape;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class AuditEntriesTable
{
    /**
     * Where a subject's record lives in the panel, by Reference type.
     * Audit sits below every module that owns a record, so it can't ask
     * their Resources for the URL: the paths are the resources' slugs.
     * A renewal reference ("domain_renewal:7:2027-01-31") leads to the
     * domain or website it belongs to.
     *
     * @var array<string, string> type → path with {id}
     */
    private const array SUBJECT_PATHS = [
        'invoice' => 'invoices/{id}',
        'supplier_invoice' => 'supplier-invoices/{id}',
        'payment_request' => 'payment-requests/{id}',
        'recurring_invoice' => 'recurring-invoices/{id}/edit',
        'pec_message' => 'pec-messages/{id}',
        'quote' => 'quotes/{id}',
        'contact' => 'contacts/{id}',
        'cash_movement' => 'cash/movements/{id}/edit',
        'recurring_expense' => 'cash/recurring-expenses/{id}/edit',
        'website' => 'websites/{id}',
        'hosting_renewal' => 'websites/{id}',
        'domain' => 'domains/{id}',
        'domain_renewal' => 'domains/{id}',
        'lead' => 'leads/{id}',
        'booking' => 'bookings/{id}',
        'campaign' => 'campaigns/{id}',
        'change_request' => 'change-requests/{id}',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('actor_id')->label('Autore')
                    ->formatStateUsing(fn (): string => 'titolare')
                    ->placeholder('sistema'),
                TextColumn::make('action')->label('Azione')->searchable(),
                TextColumn::make('subject')->label('Oggetto')->searchable()->placeholder('—')
                    ->url(fn (AuditEntry $record): ?string => self::subjectUrl($record->subject))
                    ->color(fn (AuditEntry $record): ?string => self::subjectUrl($record->subject) === null ? null : 'primary'),
                TextColumn::make('details')
                    ->label('Dettagli')
                    ->state(fn (AuditEntry $record): string => self::summarise($record->details))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('action')->label('Azione')
                    ->options(fn (): array => self::actions())
                    ->searchable(),
                TernaryFilter::make('actor')->label('Autore')
                    ->trueLabel('Titolare')
                    ->falseLabel('Sistema')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('actor_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('actor_id'),
                    ),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        $from = Shape::nullableString($data, 'from');
                        $until = Shape::nullableString($data, 'until');

                        return $query
                            ->when($from !== null, fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $from))
                            ->when($until !== null, fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $until));
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * The panel page of the record an entry is about, when it has one.
     */
    public static function subjectUrl(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        try {
            $reference = Reference::fromString($subject);
        } catch (InvalidArgumentException) {
            return null;
        }

        $path = self::SUBJECT_PATHS[$reference->type] ?? null;
        $id = explode(':', $reference->key)[0];

        if ($path === null || ! ctype_digit($id)) {
            return null;
        }

        return url('/admin/'.str_replace('{id}', $id, $path));
    }

    /**
     * @return array<string, string>
     */
    private static function actions(): array
    {
        /** @var list<string> $actions */
        $actions = AuditEntry::query()->distinct()->orderBy('action')->pluck('action')->all();

        return array_combine($actions, $actions);
    }

    /**
     * "chiave: valore" pairs, readable rather than var_export()'s PHP.
     *
     * @param  array<string, mixed>  $details
     */
    private static function summarise(array $details): string
    {
        $parts = [];
        foreach ($details as $key => $value) {
            $parts[] = $key.': '.self::value($value);
        }

        return implode(', ', $parts);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            $value === true => 'sì',
            $value === false => 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
