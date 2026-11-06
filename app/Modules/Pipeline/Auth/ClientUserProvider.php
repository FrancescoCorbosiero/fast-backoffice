<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Auth;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use SensitiveParameter;

/**
 * Resolves the portal's session to a live client contact. There are no
 * passwords: sign-in happens through an e-mailed link, so credentials
 * never validate here.
 */
final readonly class ClientUserProvider implements UserProvider
{
    public function __construct(private FindContact $contacts) {}

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        $contactId = ClientUser::contactIdFrom($identifier);
        if ($contactId === null) {
            return null;
        }

        $contact = $this->contacts->byId($contactId);
        if ($contact === null || ! $contact->hasRole(ContactRole::Client)) {
            return null;
        }

        return new ClientUser($contact->id, $contact->displayName, $contact->email);
    }

    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] mixed $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] mixed $token): void {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void {}
}
