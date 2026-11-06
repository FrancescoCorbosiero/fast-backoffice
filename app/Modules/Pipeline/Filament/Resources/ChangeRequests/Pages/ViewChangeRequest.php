<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages;

use App\Modules\Pipeline\Actions\ApproveChangeRequest;
use App\Modules\Pipeline\Actions\RejectChangeRequest;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewChangeRequest extends ViewRecord
{
    protected static string $resource = ChangeRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->request()->toData()->title();
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->request()->toData();

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($data->status->label())->badge()->color($data->status->color()),
            TextEntry::make('h_kind')->label('Tipo')->state($data->kind->label()),
            TextEntry::make('h_client')->label('Cliente')->state($data->clientName)->url('/admin/contacts/'.$data->contactId),
            TextEntry::make('h_website')->label('Sito')->state($data->websiteName)->url('/admin/websites/'.$data->websiteId),
            TextEntry::make('h_created')->label('Creata')->state($data->createdAt->isoFormat('D MMM YYYY HH:mm')),
            TextEntry::make('h_branch')->label('Branch')->state($data->branch)->fontFamily(FontFamily::Mono),
        ];
        if ($data->decidedBy !== null) {
            $header[] = TextEntry::make('h_decided')->label('Decisione')->state(($data->status->value === 'rejected' ? 'Rifiutata' : 'Approvata').' da '.$data->decidedBy->label().($data->decidedAt === null ? '' : ' il '.$data->decidedAt->isoFormat('D MMM YYYY HH:mm')).($data->decisionReason === null ? '' : ': '.$data->decisionReason));
        }
        if ($data->costCents !== null) {
            $header[] = TextEntry::make('h_cost')->label('Costo della run')->state('$ '.number_format($data->costCents / 100, 2, ',', '.'));
        }

        $components = [
            Section::make()->columns(3)->components($header),
            Section::make('Richiesta')->compact()->components([
                TextEntry::make('r_text')->label('')->state($data->requestText)->html()->formatStateUsing(fn (string $state): string => nl2br(e($state))),
            ]),
        ];

        if ($data->previewUrl !== null && $data->awaitsDecision()) {
            $components[] = Section::make('Anteprima')->compact()->components([
                TextEntry::make('p_url')->label('Apri l\'anteprima in una nuova scheda')->state($data->previewUrl)->url($data->previewUrl, true)->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('primary'),
            ]);
        }
        if ($data->error !== null) {
            $components[] = Section::make('Errore')->compact()->components([
                TextEntry::make('e_text')->label('')->state($data->error)->color('danger')->html()->formatStateUsing(fn (string $state): string => nl2br(e($state))),
            ]);
        }
        if ($data->touchedPaths !== []) {
            $components[] = Section::make('File toccati')->compact()->components([
                TextEntry::make('t_paths')->label('')->state($data->touchedPaths)->listWithLineBreaks()->fontFamily(FontFamily::Mono),
            ]);
        }
        foreach ($data->generatedFiles as $path => $content) {
            $components[] = Section::make($path)->compact()->collapsible()->components([
                TextEntry::make('g_'.md5($path))->label('')->state($content)->fontFamily(FontFamily::Mono)->html()->formatStateUsing(fn (string $state): string => nl2br(e($state)))->copyable(),
            ]);
        }
        $components[] = Section::make('Log')->compact()->collapsible()->collapsed($data->awaitsDecision() && $data->generatedFiles !== [])->components([
            TextEntry::make('l_text')->label('')->state($data->log === '' ? '(ancora vuoto)' : $data->log)->fontFamily(FontFamily::Mono)->html()->formatStateUsing(fn (string $state): string => nl2br(e($state))),
        ]);

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->label('Approva')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (): bool => $this->request()->status->awaitsDecision())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->request()->toData()->isOnboarding() ? 'pipeline.yml e CLAUDE.md vengono uniti al branch principale e il sito inizia a ricevere richieste.' : 'Il branch viene unito al branch principale e il sito pubblicato.')
                ->action(function (): void {
                    $this->run(function (): string {
                        app(ApproveChangeRequest::class)->approve($this->request()->id, DecidedBy::Owner);

                        return 'Richiesta approvata';
                    });
                }),
            Action::make('reject')->label('Rifiuta')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (): bool => $this->request()->status->awaitsDecision())
                ->schema([Textarea::make('reason')->label('Motivo')->rows(3)->maxLength(500)])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $reason = $data['reason'] ?? null;
                        app(RejectChangeRequest::class)->reject($this->request()->id, DecidedBy::Owner, is_string($reason) ? $reason : null);

                        return 'Richiesta rifiutata';
                    });
                }),
        ];
    }

    /**
     * @param  callable(): string  $callback
     */
    private function run(callable $callback): void
    {
        try {
            $message = $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

            return;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->record = $this->request()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    private function request(): ChangeRequest
    {
        $record = $this->getRecord();

        if (! $record instanceof ChangeRequest) {
            throw new LogicException('The page shows change requests.');
        }

        return $record;
    }
}
