<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Actions;

use App\Modules\Leads\Actions\MoveLead;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * "Sposta": the one modal that moves a lead through the pipeline, on
 * the lead's page, on every row of the list and on a selection of rows,
 * so working the pipeline doesn't mean opening every lead.
 */
final class MoveLeadAction
{
    public static function make(): Action
    {
        return Action::make('move')->label('Sposta')->icon(Heroicon::OutlinedArrowRight)
            ->visible(fn (Lead $record): bool => $record->status !== LeadStatus::Won)
            ->fillForm(fn (Lead $record): array => ['status' => $record->status->value, 'next_follow_up_on' => $record->next_follow_up_on?->toDateString()])
            ->schema(self::fields())
            ->action(function (Lead $record, array $data): void {
                /** @var array<string, mixed> $data */
                try {
                    $status = self::move($record, $data);
                } catch (ValidationException|LogicException $exception) {
                    self::failed($exception);

                    return;
                }

                Notification::make()->title('Stato: '.$status->label())->success()->send();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('move')->label('Sposta')->icon(Heroicon::OutlinedArrowRight)
            ->schema(self::fields())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                /** @var array<string, mixed> $data */
                $moved = 0;
                foreach ($records as $record) {
                    if (! $record instanceof Lead || $record->status === LeadStatus::Won) {
                        continue;
                    }
                    try {
                        self::move($record, $data);
                        $moved++;
                    } catch (ValidationException|LogicException $exception) {
                        self::failed($exception);

                        return;
                    }
                }

                Notification::make()->title($moved === 1 ? '1 lead spostato' : "{$moved} lead spostati")->success()->send();
            });
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function fields(): array
    {
        return [
            Select::make('status')->label('Stato')->options(array_diff_key(LeadStatus::options(), [LeadStatus::Won->value => '']))->required()->live(),
            DatePicker::make('next_follow_up_on')->label('Prossimo contatto')->visible(fn (Get $get): bool => $get('status') !== LeadStatus::Lost->value),
            Textarea::make('lost_reason')->label('Perché')->rows(2)->maxLength(500)->visible(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function move(Lead $lead, array $data): LeadStatus
    {
        $status = LeadStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '') ?? LeadStatus::New;
        $reason = $data['lost_reason'] ?? null;
        $follow = $data['next_follow_up_on'] ?? null;
        app(MoveLead::class)->move($lead->id, $status, is_string($reason) ? $reason : null, is_string($follow) && $follow !== '' ? CarbonImmutable::parse($follow) : null);

        return $status;
    }

    private static function failed(ValidationException|LogicException $exception): void
    {
        $body = $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage();
        Notification::make()->title('Operazione non riuscita')->body($body)->danger()->persistent()->send();
    }
}
