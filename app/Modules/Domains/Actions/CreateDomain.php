<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Data\DomainInput;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Validation\ValidationException;

final readonly class CreateDomain
{
    public function __construct(
        private FindContact $contacts,
        private Websites $websites,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(DomainInput $input): DomainData
    {
        $this->validate($input);

        return Domain::query()->create([...self::attributes($input), 'status' => DomainStatus::Active])->toData();
    }

    /**
     * @throws ValidationException
     */
    public function validate(DomainInput $input, ?int $ignoreId = null): void
    {
        $errors = [];

        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $input->name) !== 1) {
            $errors['name'] = 'Scrivi un nome di dominio, senza https:// e senza percorsi.';
        } elseif (Domain::query()->where('name', $input->name)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $errors['name'] = 'Questo dominio è già registrato qui.';
        }
        if ($this->contacts->byId($input->contactId) === null) {
            $errors['contact_id'] = 'Scegli il cliente.';
        }
        if ($input->websiteId !== null && $this->websites->find($input->websiteId) === null) {
            $errors['website_id'] = 'Sito sconosciuto.';
        }
        if ($input->renewalCost !== null && $input->renewalCost->isNegative()) {
            $errors['renewal_cost'] = 'Il costo non può essere negativo.';
        }
        if ($input->registeredOn !== null && $input->registeredOn->greaterThan($input->expiresOn)) {
            $errors['expires_on'] = 'La scadenza viene dopo la registrazione.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(DomainInput $input): array
    {
        return [
            'name' => $input->name,
            'contact_id' => $input->contactId,
            'website_id' => $input->websiteId,
            'registrar' => $input->registrar,
            'registered_on' => $input->registeredOn?->toDateString(),
            'expires_on' => $input->expiresOn->toDateString(),
            'renewal_months' => $input->renewalMonths,
            'auto_renew' => $input->autoRenew,
            'renewal_cost_cents' => $input->renewalCost?->cents,
            'currency' => $input->renewalCost->currency ?? 'EUR',
            'account_id' => $input->accountId,
            'category_id' => $input->categoryId,
            'notes' => $input->notes,
        ];
    }
}
