<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements\Pages;

use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Models\Movement;
use App\Support\Filament\PrefillsFromQuery;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMovement extends CreateRecord
{
    use PrefillsFromQuery;

    protected static string $resource = MovementResource::class;

    /**
     * @return list<string>
     */
    protected function prefillable(): array
    {
        return ['contact_id', 'account_id'];
    }

    /**
     * Besides the ids the trait reads, the statement links here with the
     * day and the direction of the line the owner found missing.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $prefill = $this->prefillFromQuery();
        $occurredOn = request()->query('occurred_on');
        $direction = request()->query('direction');

        if (is_string($occurredOn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredOn) === 1 && CarbonImmutable::canBeCreatedFromFormat($occurredOn, 'Y-m-d')) {
            $prefill['occurred_on'] = $occurredOn;
        }

        if (is_string($direction) && Direction::tryFrom($direction) !== null) {
            $prefill['direction'] = $direction;
        }

        if ($prefill !== []) {
            $this->form->fillPartially($prefill, array_keys($prefill));
        }
    }

    /**
     * A link to this form for a given account and day, from the statement.
     */
    public static function urlFor(?int $accountId, ?CarbonImmutable $occurredOn = null, ?Direction $direction = null): string
    {
        return static::getUrl(parameters: array_filter([
            'account_id' => $accountId !== null && $accountId > 0 ? $accountId : null,
            'occurred_on' => $occurredOn?->toDateString(),
            'direction' => $direction?->value,
        ], fn (int|string|null $value): bool => $value !== null));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $movement = app(RecordMovement::class)->record(MovementInput::fromArray($data));

        return Movement::query()->findOrFail($movement->id);
    }

    protected function getRedirectUrl(): string
    {
        return MovementResource::getUrl('index');
    }
}
