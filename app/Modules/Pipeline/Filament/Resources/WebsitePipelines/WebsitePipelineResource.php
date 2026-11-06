<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\WebsitePipelines;

use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Actions\UnlinkRepository;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages\ManageWebsitePipelines;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
            Select::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options())->searchable()->required()->live(),
            Select::make('repository_id')->label('Repository')->required()
                ->options(function (Get $get): array {
                    $websiteId = $get('website_id');
                    if (! is_numeric($websiteId)) {
                        return [];
                    }
                    $options = [];
                    foreach (app(Repositories::class)->forWebsite((int) $websiteId) as $repository) {
                        $options[$repository->id] = $repository->name.' · '.$repository->url.' ('.$repository->defaultBranch.')';
                    }

                    return $options;
                })
                ->helperText('I repository collegati al sito (Asset › Repository). L\'onboarding parte subito.'),
            Select::make('kind')->label('Tipo')->options(PipelineKind::options())->default(PipelineKind::StaticSite->value)->required(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('website_id')->label('Sito')->formatStateUsing(fn (int $state): string => self::websiteNames()[$state] ?? '#'.$state),
                TextColumn::make('repository_id')->label('Repository')->formatStateUsing(fn (int $state, Table $table): string => self::repositoryLabels($table)[$state] ?? '#'.$state),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (PipelineKind $state): string => $state->label()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (PipelineStatus $state): string => $state->label())->color(fn (PipelineStatus $state): string => $state->color()),
                TextColumn::make('updated_at')->label('Aggiornata')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(PipelineStatus::options()),
            ])
            ->recordActions([
                Action::make('onboard')->label('Ripeti onboarding')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->requiresConfirmation()->modalDescription('Il repository viene analizzato di nuovo: pipeline.yml e CLAUDE.md verranno riscritti e attenderanno la tua approvazione.')
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
        $records = $table->getRecords();
        $ids = [];
        foreach ($records instanceof Collection ? $records : $records->items() as $record) {
            if ($record instanceof WebsitePipeline) {
                $ids[] = $record->repository_id;
            }
        }

        return once(fn (): array => array_map(fn (RepositoryData $r): string => $r->url.' ('.$r->defaultBranch.')', app(Repositories::class)->byIds($ids)));
    }
}
