<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages;

use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Pipeline\Actions\ApproveChangeRequest;
use App\Modules\Pipeline\Actions\DeployChangeRequest;
use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Actions\RejectChangeRequest;
use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\PipelineActions;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\MergeOnboarding;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Support\Shape;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
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
            TextEntry::make('h_client')->label('Cliente')->state($data->clientName)->url(ContactLink::url($data->contactId)),
            TextEntry::make('h_website')->label('Sito')->state($data->websiteName)->url(WebsiteLink::url($data->websiteId)),
            TextEntry::make('h_created')->label('Creata')->state($data->createdAt->isoFormat('D MMM YYYY HH:mm')),
            TextEntry::make('h_branch')->label('Branch')->state($data->branch)->fontFamily(FontFamily::Mono),
        ];
        if ($data->decidedBy !== null) {
            $header[] = TextEntry::make('h_decided')->label('Decisione')->state(($data->status->value === 'rejected' ? 'Rifiutata' : 'Approvata').' da '.$data->decidedBy->label().($data->decidedAt === null ? '' : ' il '.$data->decidedAt->isoFormat('D MMM YYYY HH:mm')).($data->decisionReason === null ? '' : ': '.$data->decisionReason));
        }
        if ($data->status === ChangeRequestStatus::Approved) {
            $header[] = TextEntry::make('h_deploy')->label('Pubblicazione')
                ->state($data->deployStartedAt === null ? 'in attesa del worker' : 'avviata il '.$data->deployStartedAt->isoFormat('D MMM YYYY HH:mm'))
                ->color(self::deployStale($data) ? 'warning' : null)
                ->helperText(self::deployStale($data) ? 'Il worker non l\'ha presa in carico: "Riprova pubblicazione" la rimette in coda.' : null);
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

        // The preview stays online until the deploy removes it: still worth a look once approved.
        if ($data->previewUrl !== null && in_array($data->status, [ChangeRequestStatus::PreviewReady, ChangeRequestStatus::Approved], true)) {
            $components[] = Section::make($data->awaitsDecision() ? 'Anteprima' : 'Anteprima (viene rimossa alla pubblicazione)')->compact()->components([
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
            // A failed or rejected request: the same client, the same site, the text ready to adjust.
            Action::make('retry')->label('Ripeti richiesta')->icon(Heroicon::OutlinedArrowPath)->color('primary')
                ->visible(fn (): bool => $this->isOver() && $this->request()->kind === ChangeRequestKind::Change)
                ->modalHeading('Ripeti la richiesta')
                ->modalDescription(fn (): string => 'Per '.$this->request()->client_name.' su '.$this->request()->website_name.': una nuova richiesta, lavorata da capo.')
                ->modalSubmitActionLabel('Invia')
                ->fillForm(fn (): array => ['request_text' => $this->request()->request_text])
                ->schema([Textarea::make('request_text')->label('Richiesta')->rows(6)->required()->maxLength(SubmitChangeRequest::MAX_LENGTH)])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $current = $this->request();
                        $new = app(SubmitChangeRequest::class)->submit(new ChangeRequestInput($current->contact_id, $current->website_id, Shape::string($data, 'request_text')));
                        $this->redirect(ChangeRequestResource::getUrl('view', ['record' => $new->id]));

                        return 'Richiesta #'.$new->id.' inviata';
                    });
                }),
            Action::make('reonboard')->label('Ripeti onboarding')->icon(Heroicon::OutlinedArrowPath)->color('primary')
                ->visible(fn (): bool => $this->isOver() && $this->request()->kind === ChangeRequestKind::Onboarding && app(WebsitePipelines::class)->forWebsite($this->request()->website_id) !== null)
                ->requiresConfirmation()->modalDescription(PipelineActions::REONBOARD_DESCRIPTION)
                ->action(function (): void {
                    $this->run(function (): string {
                        $pipeline = app(WebsitePipelines::class)->forWebsite($this->request()->website_id) ?? throw new LogicException('Il sito non ha più un repository collegato.');
                        app(LinkRepository::class)->link($pipeline->websiteId, $pipeline->repositoryId, $pipeline->kind);
                        $new = ChangeRequest::query()->where('website_id', $pipeline->websiteId)->where('kind', ChangeRequestKind::Onboarding->value)->orderByDesc('id')->first();
                        if ($new !== null) {
                            $this->redirect(ChangeRequestResource::getUrl('view', ['record' => $new]));
                        }

                        return 'Onboarding avviato';
                    });
                }),
            // Approved but not claimed by a worker: the claim makes a second dispatch harmless (pipeline.md §2).
            Action::make('redeploy')->label('Riprova pubblicazione')->icon(Heroicon::OutlinedRocketLaunch)->color('warning')
                ->visible(fn (): bool => self::deployStale($this->request()->toData()))
                ->requiresConfirmation()->modalDescription('La pubblicazione viene rimessa in coda per il worker.')
                ->action(function (): void {
                    $this->run(function (): string {
                        $data = $this->request()->toData();
                        app(Bus::class)->dispatch($data->isOnboarding() ? new MergeOnboarding($data->id) : new DeployStaticSiteRequest($data->id));

                        return 'Pubblicazione rimessa in coda';
                    });
                }),
        ];
    }

    /**
     * Approved, and no worker has it: never claimed, or claimed longer ago
     * than a worker may run.
     */
    private static function deployStale(ChangeRequestData $data): bool
    {
        if ($data->status !== ChangeRequestStatus::Approved) {
            return false;
        }

        return $data->deployStartedAt === null || $data->deployStartedAt->lessThan(DeployChangeRequest::claimStaleBefore(app(PipelineConfig::class)));
    }

    private function isOver(): bool
    {
        return in_array($this->request()->status, [ChangeRequestStatus::Failed, ChangeRequestStatus::Rejected], true);
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
