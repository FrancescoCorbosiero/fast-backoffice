<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Contacts\Queries\SearchContacts;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ViewChangeRequest;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The clients' change requests and the onboarding runs: what was asked,
 * what the run did, the preview, and the owner's decision.
 */
class ChangeRequestResource extends Resource
{
    protected static ?string $model = ChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 33;

    protected static ?string $modelLabel = 'richiesta di modifica';

    protected static ?string $pluralModelLabel = 'Richieste di modifica';

    protected static ?string $slug = 'change-requests';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->description('Una richiesta per conto del cliente: la pipeline la lavora come se arrivasse dal portale.')->components([
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required()->live(),
                Select::make('website_id')->label('Sito')->required()->searchable()
                    ->options(fn (Get $get): array => self::readyWebsites($get('contact_id')))
                    ->helperText(function (Get $get): string|Htmlable {
                        $contactId = $get('contact_id');
                        if (is_numeric($contactId) && self::readyWebsites($contactId) === []) {
                            return new HtmlString('Questo cliente non ha siti con la pipeline pronta: <a href="'.e(WebsitePipelineResource::getUrl('index')).'" class="fi-link">collega un repository</a> in Pipeline siti.');
                        }

                        return 'I siti del cliente con la pipeline pronta.';
                    }),
                Textarea::make('request_text')->label('Richiesta')->rows(6)->required()->maxLength(4000)->placeholder('Cosa va cambiato sul sito, con parole semplici.')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('client_name')->label('Cliente')->searchable()->weight('bold')->url(fn (ChangeRequest $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('website_name')->label('Sito')->searchable()->url(fn (ChangeRequest $record): string => WebsiteLink::url($record->website_id)),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (ChangeRequestKind $state): string => $state->label())->color(fn (ChangeRequestKind $state): string => $state->color()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (ChangeRequestStatus $state): string => $state->label())->color(fn (ChangeRequestStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Creata')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(ChangeRequestStatus::options()),
                SelectFilter::make('kind')->label('Tipo')->options(ChangeRequestKind::options()),
                SelectFilter::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options())->searchable(),
                SelectFilter::make('contact_id')->label('Cliente')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::contactLabels(app(SearchContacts::class)->search($search, ContactRole::Client, 25)))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? app(FindContact::class)->byId((int) $value)?->displayName : null),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading('Nessuna richiesta')
            ->emptyStateDescription(fn (): string => self::anyPipelineReady() ? 'Le richieste dei clienti dal portale compaiono qui; puoi anche farne una per conto del cliente.' : 'Prima collega un repository a un sito: la pipeline deve essere pronta.')
            ->emptyStateActions([
                Action::make('new')->label('Nuova richiesta')->icon(Heroicon::OutlinedPlus)->url(self::getUrl('create'))->visible(fn (): bool => self::anyPipelineReady()),
                Action::make('pipelines')->label('Pipeline siti')->icon(Heroicon::OutlinedRocketLaunch)->url(WebsitePipelineResource::getUrl('index'))->visible(fn (): bool => ! self::anyPipelineReady()),
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
        return [
            'index' => ListChangeRequests::route('/'),
            'create' => CreateChangeRequest::route('/create'),
            'view' => ViewChangeRequest::route('/{record}'),
        ];
    }

    /**
     * The chosen client's websites that take requests; nothing until a
     * client is chosen, so another client's site can't be picked.
     *
     * @return array<int, string>
     */
    private static function readyWebsites(mixed $contactId): array
    {
        if (! is_numeric($contactId)) {
            return [];
        }
        $options = [];
        foreach (app(WebsitePipelines::class)->readyForContact((int) $contactId) as $website) {
            $options[$website->id] = $website->name.' · '.$website->host;
        }

        return $options;
    }

    private static function anyPipelineReady(): bool
    {
        return once(fn (): bool => app(WebsitePipelines::class)->readyOptions() !== []);
    }

    /**
     * @param  list<ContactData>  $contacts
     * @return array<int, string>
     */
    private static function contactLabels(array $contacts): array
    {
        $labels = [];
        foreach ($contacts as $contact) {
            $labels[$contact->id] = $contact->displayName;
        }

        return $labels;
    }
}
