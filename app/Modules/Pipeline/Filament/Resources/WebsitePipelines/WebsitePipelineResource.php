<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\WebsitePipelines;

use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Actions\UnlinkRepository;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages\ManageWebsitePipelines;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/**
 * Which websites are built by the pipeline, from which repository, and
 * whether they are ready. Linking starts the onboarding run.
 */
class WebsitePipelineResource extends Resource
{
    protected static ?string $model = WebsitePipeline::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 35;

    protected static ?string $modelLabel = 'pipeline';

    protected static ?string $pluralModelLabel = 'Pipeline siti';

    protected static ?string $slug = 'website-pipelines';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options())->searchable()->required()->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    // One repository for the site: no reason to make the owner pick it.
                    $repositories = is_numeric($state) ? app(Repositories::class)->forWebsite((int) $state) : [];
                    $set('repository_id', count($repositories) === 1 ? $repositories[0]->id : null);
                }),
            Select::make('repository_id')->label('Repository')->required()
                ->options(fn (Get $get): array => self::repositoryOptions($get('website_id')))
                ->helperText(function (Get $get): string|Htmlable {
                    $websiteId = $get('website_id');
                    if (is_numeric($websiteId) && self::repositoryOptions($websiteId) === []) {
                        return new HtmlString('Nessun repository per questo sito: <a href="'.e(WebsiteLink::url((int) $websiteId)).'" class="fi-link">aggiungilo dalla pagina del sito</a> (sezione Repository), poi torna qui.');
                    }

                    return 'I repository collegati al sito (Asset › Repository). L\'onboarding parte subito.';
                }),
            Select::make('kind')->label('Tipo')->options(PipelineKind::options())->default(PipelineKind::StaticSite->value)->required(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('website_id')->label('Sito')->formatStateUsing(fn (int $state): string => self::websiteNames()[$state] ?? '#'.$state)
                    ->url(fn (WebsitePipeline $record): string => WebsiteLink::url($record->website_id)),
                TextColumn::make('repository_id')->label('Repository')->formatStateUsing(fn (int $state, Table $table): string => self::repositoryLabels($table)[$state] ?? '#'.$state)
                    ->url(fn (WebsitePipeline $record, Table $table): ?string => self::repositoryUrls($table)[$record->repository_id] ?? null, true),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (PipelineKind $state): string => $state->label()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (PipelineStatus $state): string => $state->label())->color(fn (PipelineStatus $state): string => $state->color()),
                TextColumn::make('updated_at')->label('Aggiornata')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(PipelineStatus::options()),
            ])
            ->recordActions([
                Action::make('onboarding')->label(fn (WebsitePipeline $record): string => self::onboardingLabel($record->website_id))->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('warning')
                    ->visible(fn (WebsitePipeline $record): bool => $record->status === PipelineStatus::Onboarding)
                    ->url(fn (WebsitePipeline $record): ?string => self::onboardingUrl($record->website_id)),
                Action::make('requests')->label('Richieste')->icon(Heroicon::OutlinedSparkles)->color('gray')
                    ->url(fn (WebsitePipeline $record): string => self::requestsUrl($record->website_id)),
                Action::make('onboard')->label('Ripeti onboarding')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->requiresConfirmation()->modalDescription(PipelineActions::REONBOARD_DESCRIPTION)
                    ->action(fn (WebsitePipeline $record) => self::run(function () use ($record): string {
                        app(LinkRepository::class)->link($record->website_id, $record->repository_id, $record->kind);

                        return 'Onboarding avviato';
                    })),
                Action::make('unlink')->label('Scollega')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (WebsitePipeline $record) => self::run(function () use ($record): string {
                        app(UnlinkRepository::class)->unlink($record->id);

                        return 'Repository scollegato';
                    })),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRocketLaunch)
            ->emptyStateHeading('Nessun sito collegato')
            ->emptyStateDescription('Collega un repository a un sito per attivare le richieste di modifica.')
            ->emptyStateActions([PipelineActions::link()])
            ->paginated([25, 50]);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageWebsitePipelines::route('/')];
    }

    /**
     * The requests of one website, in Asset › Richieste di modifica.
     */
    public static function requestsUrl(int $websiteId): string
    {
        return ChangeRequestResource::getUrl('index', ['filters' => ['website_id' => ['value' => $websiteId]]]);
    }

    /**
     * The onboarding request of a website in onboarding: where the owner
     * reads pipeline.yml and CLAUDE.md and approves them.
     */
    public static function onboardingUrl(int $websiteId): ?string
    {
        $request = self::onboardingRequest($websiteId);

        return $request === null ? null : ChangeRequestResource::getUrl('view', ['record' => $request]);
    }

    public static function onboardingLabel(int $websiteId): string
    {
        $request = self::onboardingRequest($websiteId);

        return $request !== null && $request->status->awaitsDecision() ? 'Onboarding da approvare' : 'Onboarding in corso';
    }

    /**
     * @param  callable(): string  $callback
     */
    public static function run(callable $callback): void
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

        Notification::make()->title($message)->success()->send();
    }

    private static function onboardingRequest(int $websiteId): ?ChangeRequest
    {
        return once(fn (): ?ChangeRequest => ChangeRequest::query()->where('website_id', $websiteId)->where('kind', ChangeRequestKind::Onboarding->value)
            ->whereNotIn('status', [ChangeRequestStatus::Rejected->value, ChangeRequestStatus::Deployed->value])->orderByDesc('id')->first());
    }

    /**
     * @return array<int, string>
     */
    private static function repositoryOptions(mixed $websiteId): array
    {
        if (! is_numeric($websiteId)) {
            return [];
        }
        $options = [];
        foreach (app(Repositories::class)->forWebsite((int) $websiteId) as $repository) {
            $options[$repository->id] = $repository->name.' · '.$repository->url.' ('.$repository->defaultBranch.')';
        }

        return $options;
    }

    /**
     * One query for the page, whatever the number of rows.
     *
     * @return array<int, string>
     */
    private static function websiteNames(): array
    {
        return once(function (): array {
            $names = [];
            foreach (app(Websites::class)->active() as $website) {
                $names[$website->id] = $website->name.' · '.$website->host;
            }

            return $names;
        });
    }

    /**
     * @return array<int, string>
     */
    private static function repositoryLabels(Table $table): array
    {
        return array_map(fn (RepositoryData $r): string => $r->url.' ('.$r->defaultBranch.')', self::repositories($table));
    }

    /**
     * @return array<int, string>
     */
    private static function repositoryUrls(Table $table): array
    {
        return array_map(fn (RepositoryData $r): string => $r->url, self::repositories($table));
    }

    /**
     * @return array<int, RepositoryData>
     */
    private static function repositories(Table $table): array
    {
        $records = $table->getRecords();
        $ids = [];
        foreach ($records instanceof Collection ? $records : $records->items() as $record) {
            if ($record instanceof WebsitePipeline) {
                $ids[] = $record->repository_id;
            }
        }

        return once(fn (): array => app(Repositories::class)->byIds($ids));
    }
}
