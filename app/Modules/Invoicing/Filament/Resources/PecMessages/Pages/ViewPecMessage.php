<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PecMessages\Pages;

use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\ProcessPecMessage;
use App\Modules\Invoicing\Actions\ResolvePecMessage;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\PecMessages\PecMessageResource;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Models\PecMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ViewPecMessage extends ViewRecord
{
    protected static string $resource = PecMessageResource::class;

    public function content(Schema $schema): Schema
    {
        $data = $this->message()->toData();

        $entries = [
            TextEntry::make('m_kind')->label('Tipo')->state($data->kind->label()),
            TextEntry::make('m_outcome')->label('Esito')->state($data->outcome->label())->badge()->color($data->outcome->color()),
            TextEntry::make('m_received')->label('Ricevuto')->state($data->receivedAt?->isoFormat('D MMM YYYY HH:mm') ?? '—'),
            TextEntry::make('m_from')->label('Da')->state($data->from ?? '—'),
            TextEntry::make('m_subject')->label('Oggetto')->state($data->subject ?? '—')->columnSpanFull(),
            TextEntry::make('m_file')->label('File')->state($data->fileName ?? '—'),
            TextEntry::make('m_sdi')->label('Identificativo SdI')->state($data->sdiIdentifier ?? '—'),
            TextEntry::make('m_id')->label('Message-ID')->state($data->messageId)->columnSpanFull(),
        ];
        if ($data->note !== null) {
            $entries[] = TextEntry::make('m_note')->label('Note')->state($data->note)->columnSpanFull();
        }
        if ($data->invoiceId !== null) {
            $entries[] = TextEntry::make('m_invoice')->label('Fattura')->state('apri')->url(InvoiceResource::getUrl('view', ['record' => $data->invoiceId]));
        }
        if ($data->supplierInvoiceId !== null) {
            $entries[] = TextEntry::make('m_supplier')->label('Fattura fornitore')->state('apri')->url(SupplierInvoiceResource::getUrl('view', ['record' => $data->supplierInvoiceId]));
        }

        return $schema->components([Section::make()->columns(2)->components($entries)]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raw')->label('Scarica messaggio')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->visible(fn (): bool => $this->message()->raw_file_id !== null)
                ->action(function (): StreamedResponse {
                    $contents = app(ReadFile::class)->contents($this->message()->raw_file_id ?? 0);

                    return response()->streamDownload(fn () => print $contents, 'message-'.$this->message()->id.'.eml', ['Content-Type' => 'message/rfc822']);
                }),
            Action::make('reprocess')->label('Rielabora')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                ->visible(fn (): bool => $this->needsReview() && $this->message()->raw_file_id !== null)
                ->requiresConfirmation()
                ->modalDescription('Il messaggio conservato viene letto di nuovo, come se fosse appena arrivato: utile dopo aver sistemato la causa (profilo aziendale, fattura in uscita).')
                ->action(function (): void {
                    try {
                        $result = app(ProcessPecMessage::class)->reprocess($this->message()->id);
                    } catch (Throwable $exception) {
                        Notification::make()->title('Rielaborazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $this->record = $this->message()->refresh();
                    Notification::make()->title('Messaggio rielaborato: '.$result->outcome->label())->body($result->note ?? '')->status($result->outcome === PecMessageOutcome::Processed ? 'success' : 'warning')->send();
                }),
            Action::make('import')->label('Importa manualmente')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn (): bool => $this->needsReview() && $this->message()->kind === PecMessageKind::SupplierInvoice)
                ->tooltip('Scarica il messaggio, estrai il file XML e caricalo da «Importa XML» nelle fatture fornitori.')
                ->url(SupplierInvoiceResource::getUrl('index')),
            Action::make('resolve')->label('Segna come gestito')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (): bool => $this->needsReview())
                ->schema([Textarea::make('note')->label('Cosa hai fatto')->rows(2)->maxLength(2000)])
                ->action(function (array $data): void {
                    $note = $data['note'] ?? null;
                    app(ResolvePecMessage::class)->resolve($this->message()->id, is_string($note) && trim($note) !== '' ? trim($note) : null);
                    $this->record = $this->message()->refresh();
                    Notification::make()->title('Messaggio gestito')->success()->send();
                }),
        ];
    }

    private function needsReview(): bool
    {
        return in_array($this->message()->outcome, [PecMessageOutcome::Review, PecMessageOutcome::Failed], true);
    }

    private function message(): PecMessage
    {
        $record = $this->getRecord();

        if (! $record instanceof PecMessage) {
            throw new LogicException('The page shows PEC messages.');
        }

        return $record;
    }
}
