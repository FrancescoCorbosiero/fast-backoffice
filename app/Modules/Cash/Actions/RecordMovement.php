<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Events\MovementRecorded;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Records money moving. The one way a movement comes into being, from
 * the Cash screens and from the modules that allocate movements to
 * their documents (payments, renewals, logged expenses).
 */
final readonly class RecordMovement
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function record(MovementInput $input): MovementData
    {
        self::validate($input);

        return $this->db->transaction(function () use ($input): MovementData {
            $movement = Movement::query()->create(self::attributes($input));
            $data = $movement->toData();

            $this->events->dispatch(new MovementRecorded($data->id, $input->allocation?->toString()));

            return $data;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function validate(MovementInput $input): void
    {
        $errors = [];

        if (! $input->amount->isPositive()) {
            $errors['amount'] = 'L\'importo è maggiore di zero: il verso dice se entra o esce.';
        }

        if (trim($input->description) === '') {
            $errors['description'] = 'Serve una descrizione.';
        }

        $account = Account::query()->find($input->accountId);

        if ($account === null || ! $account->is_active) {
            $errors['account_id'] = 'Scegli un conto attivo.';
        } elseif ($account->currency !== $input->amount->currency) {
            $errors['amount'] = "Il conto è in {$account->currency}.";
        } elseif ($input->occurredOn->lessThan($account->opened_on)) {
            $errors['occurred_on'] = 'Il movimento è prima dell\'apertura del conto.';
        }

        if ($input->categoryId !== null) {
            $category = Category::query()->find($input->categoryId);

            if ($category === null) {
                $errors['category_id'] = 'Categoria sconosciuta.';
            } elseif ($category->kind !== $input->direction->categoryKind()) {
                $errors['category_id'] = 'La categoria è di '.$category->kind->label().', il movimento è un\''.strtolower($input->direction->label()).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(MovementInput $input): array
    {
        return [
            'account_id' => $input->accountId,
            'direction' => $input->direction,
            'amount_cents' => $input->amount->cents,
            'currency' => $input->amount->currency,
            'occurred_on' => $input->occurredOn->toDateString(),
            'description' => trim($input->description),
            'category_id' => $input->categoryId,
            'contact_id' => $input->contactId,
            'allocation' => $input->allocation?->toString(),
            'is_taxable' => $input->isTaxable,
            'notes' => $input->notes,
        ];
    }
}
