<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Database\Factories;

use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'kind' => ContactKind::Individual,
            'display_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'vat_country' => null,
            'vat_number' => null,
            'tax_code' => null,
            'street' => fake()->streetName(),
            'street_number' => (string) fake()->numberBetween(1, 200),
            'postcode' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'province' => 'MI',
            'country' => 'IT',
            'roles' => [ContactRole::Client->value],
            'email_consent' => true,
        ];
    }

    public function company(string $name = 'Studio Verdi Srl'): static
    {
        return $this->state(fn (): array => [
            'kind' => ContactKind::Company,
            'display_name' => $name,
            'business_name' => $name,
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    public function withVatNumber(string $number = '01234567897', string $country = 'IT'): static
    {
        return $this->state(fn (): array => ['vat_country' => $country, 'vat_number' => $number]);
    }

    public function withTaxCode(string $code = 'RSSMRA85T10A562S'): static
    {
        return $this->state(fn (): array => ['tax_code' => $code]);
    }

    public function supplier(): static
    {
        return $this->state(fn (): array => ['roles' => [ContactRole::Supplier->value]]);
    }

    /**
     * @param  list<ContactRole>  $roles
     */
    public function roles(array $roles): static
    {
        return $this->state(fn (): array => ['roles' => array_map(fn (ContactRole $role): string => $role->value, $roles)]);
    }

    public function suppressed(string $reason = 'bounce'): static
    {
        return $this->state(fn (): array => ['email_suppressed_at' => now(), 'email_suppression_reason' => $reason]);
    }

    public function withoutConsent(): static
    {
        return $this->state(fn (): array => ['email_consent' => false]);
    }

    public function abroad(string $country = 'DE'): static
    {
        return $this->state(fn (): array => ['country' => $country, 'province' => null, 'vat_country' => $country, 'vat_number' => '123456789']);
    }
}
