<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages;

use App\Modules\Invoicing\Actions\ImportFatturaPa;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListSupplierInvoices extends ListRecords
{
    protected static string $resource = SupplierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Importa XML')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription('File FatturaPA ricevuti dai fornitori, o fatture emesse altrove quest\'anno: entrambi vengono riconosciuti dal profilo aziendale.')
                ->schema([
                    FileUpload::make('files')->label('File XML')->multiple()->acceptedFileTypes(['application/xml', 'text/xml', '.xml'])->preserveFilenames()->storeFiles(false)->required(),
                ])
                ->action(function (array $data): void {
                    $lines = [];
                    $files = $data['files'] ?? [];

                    foreach (is_array($files) ? $files : [] as $file) {
                        if (! $file instanceof TemporaryUploadedFile) {
                            continue;
                        }

                        $result = app(ImportFatturaPa::class)->import($file->getClientOriginalName(), (string) $file->get());
                        $lines[] = $result->fileName.': '.match ($result->status) {
                            'imported' => 'importata',
                            'skipped' => 'saltata ('.$result->reason.')',
                            default => 'errore, '.$result->reason,
                        };
                    }

                    Notification::make()->title('Importazione completata')->body(implode("\n", $lines))->success()->persistent()->send();
                }),
        ];
    }
}
