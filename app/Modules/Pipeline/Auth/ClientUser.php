<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A client signed in to the portal: a contact, not a row of its own. The
 * identifier is deliberately not numeric, so nothing that stores "the
 * authenticated user's id" (the audit trail) mistakes a contact for the
 * owner.
 */
final class ClientUser implements Authenticatable
{
    public const string PREFIX = 'contact:';

    public function __construct(
        public readonly int $contactId,
        public readonly string $name,
        public readonly ?string $email,
    ) {}

    public static function contactIdFrom(mixed $identifier): ?int
    {
        if (! is_string($identifier) || ! str_starts_with($identifier, self::PREFIX)) {
            return null;
        }
        $id = substr($identifier, strlen(self::PREFIX));

        return ctype_digit($id) && $id !== '0' ? (int) $id : null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'contact';
    }

    public function getAuthIdentifier(): string
    {
        return self::PREFIX.$this->contactId;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
