<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use App\Kernel\Vat\Natura;
use App\Modules\Settings\Actions\SaveInvoiceDefaults;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class InvoiceDefaultsPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Fatture: impostazioni';

    protected static ?string $title = 'Impostazioni di fatturazione';

    protected static ?string $slug = 'settings/invoices';

    protected static ?int $navigationSort = 2;

    public function form(Schema $schema): Schema
    {
        $naturaOptions = [];
        foreach (Natura::cases() as $natura) {
            $naturaOptions[$natura->value] = $natura->value.' – '.$natura->label();
        }

        return parent::form($schema)->components([
            Section::make('Termini e diciture')->columns(2)->components([
                TextInput::make('payment_terms_days')->label('Termini di pagamento (giorni)')->integer()->minValue(0)->maxValue(365)->required(),
                Select::make('default_natura')->label('Natura predefinita per le righe senza IVA')->options($naturaOptions)->searchable(),
                Textarea::make('legal_wording')->label('Dicitura legale sulla fattura di cortesia')->rows(3)->columnSpanFull()->maxLength(1000),
            ]),
            Section::make('Bollo e contributi')->columns(2)->components([
                Toggle::make('stamp_duty_charged_to_customer')->label('Addebita l\'imposta di bollo (€ 2) al cliente'),
                Toggle::make('inps_contribution_enabled')->label('Applica la rivalsa INPS (gestione separata)')->live(),
                TextInput::make('inps_contribution_basis_points')->label('Rivalsa INPS (punti base: 4% = 400)')->integer()->minValue(0)->maxValue(10000)
                    ->visible(fn (Get $get): bool => (bool) $get('inps_contribution_enabled'))->required(),
            ]),
            Section::make('Numerazione')->columns(2)->components([
                Select::make('invoice_series')->label('Serie delle fatture')->options($this->seriesOptions())->required(),
                Select::make('credit_note_series')->label('Serie delle note di credito')->options($this->seriesOptions())->required(),
            ]),
        ]);
    }

    protected function load(): array
    {
        return app(GetInvoiceDefaults::class)->get()->toArray();
    }

    protected function persist(array $state): void
    {
        app(SaveInvoiceDefaults::class)->save(InvoiceDefaults::fromArray($state));
    }

    /**
     * @return array<string, string>
     */
    private function seriesOptions(): array
    {
        $options = [];
        foreach (app(GetNumberingSeries::class)->get()->series as $series) {
            $options[$series->code] = $series->label.' ('.$series->code.')';
        }

        return $options;
    }
}
