<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament\Pages;

use App\Modules\Scheduling\Actions\OverrideJob;
use App\Modules\Scheduling\Actions\RunJob;
use App\Modules\Scheduling\Data\JobData;
use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Queries\ListJobs;
use BackedEnum;
use Closure;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * The jobs defined in code, with their overrides and last run, and the
 * buttons to run one now, pause it or change its schedule.
 */
class JobsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Operazioni pianificate';

    protected static ?string $title = 'Operazioni pianificate';

    protected static ?string $slug = 'system/jobs';

    protected static ?int $navigationSort = 10;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('label')->label('Operazione')->description(fn (array $record): string => self::string($record, 'description')),
                TextColumn::make('key')->label('Chiave')->fontFamily('mono')->size('xs'),
                TextColumn::make('cron')->label('Pianificazione')->fontFamily('mono')
                    ->description(fn (array $record): ?string => self::bool($record, 'overridden') ? 'predefinita: '.self::string($record, 'default_cron') : null),
                IconColumn::make('enabled')->label('Attiva')->boolean(),
                TextColumn::make('last_run_at')->label('Ultima esecuzione')->dateTime('d/m/Y H:i')->placeholder('mai'),
                TextColumn::make('last_exit_code')->label('Esito')
                    ->state(fn (array $record): string => match ($record['last_exit_code'] ?? null) {
                        null => isset($record['last_run_at']) ? 'in corso' : '—',
                        0 => 'ok',
                        default => 'errore ('.self::string($record, 'last_exit_code').')',
                    })
                    ->color(fn (array $record): string => match ($record['last_exit_code'] ?? null) {
                        null => 'gray',
                        0 => 'success',
                        default => 'danger',
                    })
                    ->badge(),
            ])
            ->recordActions([
                Action::make('run')
                    ->label('Esegui ora')
                    ->icon(Heroicon::OutlinedPlay)
                    ->requiresConfirmation()
                    ->modalDescription('L\'operazione viene eseguita subito, in questa richiesta.')
                    ->action(function (array $record): void {
                        $run = app(RunJob::class)->run(self::string($record, 'key'), RunTrigger::Manual);
                        $notification = Notification::make()->title($run->succeeded() ? 'Eseguita' : 'Terminata con errore')->body($run->output);
                        $run->succeeded() ? $notification->success()->send() : $notification->danger()->send();
                    }),
                Action::make('toggle')
                    ->label(fn (array $record): string => self::bool($record, 'enabled') ? 'Sospendi' : 'Riattiva')
                    ->icon(fn (array $record): Heroicon => self::bool($record, 'enabled') ? Heroicon::OutlinedPause : Heroicon::OutlinedPlayCircle)
                    ->action(function (array $record): void {
                        $cron = self::bool($record, 'overridden') ? self::string($record, 'cron') : null;
                        app(OverrideJob::class)->override(self::string($record, 'key'), ! self::bool($record, 'enabled'), $cron);
                        Notification::make()->title('Operazione aggiornata')->success()->send();
                    }),
                Action::make('schedule')
                    ->label('Pianificazione')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->fillForm(fn (array $record): array => ['cron' => self::string($record, 'cron')])
                    ->schema([
                        TextInput::make('cron')->label('Espressione cron (minuto ora giorno mese giorno-settimana)')->required()->rules([fn (): Closure => self::cronRule()]),
                    ])
                    ->action(function (array $record, array $data): void {
                        $enabled = self::bool($record, 'enabled') ? null : false;
                        app(OverrideJob::class)->override(self::string($record, 'key'), $enabled, self::string($data, 'cron'));
                        Notification::make()->title('Pianificazione aggiornata')->success()->send();
                    }),
                Action::make('reset')
                    ->label('Ripristina')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (array $record): bool => self::bool($record, 'overridden'))
                    ->action(function (array $record): void {
                        app(OverrideJob::class)->override(self::string($record, 'key'), null, null);
                        Notification::make()->title('Impostazioni predefinite ripristinate')->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (app(ListJobs::class)->get() as $job) {
            $rows[$job->key] = self::row($job);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(JobData $job): array
    {
        return [
            'key' => $job->key,
            'label' => $job->label,
            'description' => $job->description,
            'cron' => $job->cron,
            'default_cron' => $job->defaultCron,
            'enabled' => $job->enabled,
            'overridden' => $job->overridden,
            'last_run_at' => $job->lastRun?->startedAt,
            'last_exit_code' => $job->lastRun?->exitCode,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private static function string(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private static function bool(array $record, string $key): bool
    {
        return ($record[$key] ?? false) === true;
    }

    private static function cronRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                    throw new InvalidArgumentException('Non è un\'espressione cron valida.');
                }
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
