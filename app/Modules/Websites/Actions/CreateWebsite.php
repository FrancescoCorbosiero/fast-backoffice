<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Data\WebsiteInput;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Models\Website;
use Illuminate\Validation\ValidationException;

final readonly class CreateWebsite
{
    public function __construct(
        private FindContact $contacts,
        private Accounts $accounts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(WebsiteInput $input): WebsiteData
    {
        $this->validate($input);

        $website = Website::query()->create([...self::attributes($input), 'status' => WebsiteStatus::Active]);

        return $website->toData();
    }

    /**
     * @throws ValidationException
     */
    public function validate(WebsiteInput $input, ?int $ignoreId = null): void
    {
        $errors = [];

        if ($input->name === '') {
            $errors['name'] = 'Dai un nome al sito.';
        }
        if (self::host($input->url) === null) {
            $errors['url'] = 'Scrivi un indirizzo completo, con https://.';
        }
        if ($this->contacts->byId($input->contactId) === null) {
            $errors['contact_id'] = 'Scegli il cliente.';
        }
        if ($input->hostingCost !== null && $input->hostingCost->isNegative()) {
            $errors['hosting_cost'] = 'Il costo non può essere negativo.';
        }
        if ($input->hostingCost !== null && $input->hostingCost->isPositive() && ($input->hostingRenewsOn === null || $input->hostingFrequency === null)) {
            $errors['hosting_renews_on'] = 'Un hosting a pagamento ha una scadenza e una cadenza.';
        }
        if ($input->hostingAccountId !== null && $this->accounts->find($input->hostingAccountId) === null) {
            $errors['hosting_account_id'] = 'Conto sconosciuto.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(WebsiteInput $input): array
    {
        return [
            'contact_id' => $input->contactId,
            'name' => $input->name,
            'url' => $input->url,
            'host' => self::host($input->url) ?? '',
            'hosting_provider' => $input->hostingProvider,
            'hosting_started_on' => ($input->hostingStartedOn ?? $input->hostingRenewsOn)?->toDateString(),
            'hosting_renews_on' => $input->hostingRenewsOn?->toDateString(),
            'hosting_frequency' => $input->hostingFrequency,
            'hosting_cost_cents' => $input->hostingCost?->cents,
            'currency' => $input->hostingCost->currency ?? 'EUR',
            'hosting_account_id' => $input->hostingAccountId,
            'hosting_category_id' => $input->hostingCategoryId,
            'uptime_enabled' => $input->uptimeEnabled,
            'notes' => $input->notes,
        ];
    }

    /**
     * The host the URL points at, lower-case: the indexed column searches run on.
     */
    public static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        return mb_strtolower($parts['host']);
    }
}
