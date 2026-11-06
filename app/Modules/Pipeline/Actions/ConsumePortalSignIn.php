<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Auth\ClientUserProvider;
use App\Modules\Pipeline\Models\PortalSignIn;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Date;

/**
 * The link in the mail: valid once, for a few minutes. Looking at it
 * (the page with the button) consumes nothing; pressing the button does,
 * so a mail client that prefetches links can't burn it.
 */
final readonly class ConsumePortalSignIn
{
    public function __construct(private ClientUserProvider $clients) {}

    public function isValid(string $token): bool
    {
        return $this->pending($token) !== null;
    }

    /**
     * The client the token stands for, consuming it; null when it is
     * unknown, used or expired.
     */
    public function consume(string $token): ?ClientUser
    {
        $signIn = $this->pending($token);
        if ($signIn === null) {
            return null;
        }

        $consumed = PortalSignIn::query()->whereKey($signIn->id)->whereNull('used_at')->update(['used_at' => Date::now()]) === 1;
        if (! $consumed) {
            return null;
        }

        $user = $this->clients->retrieveById(ClientUser::PREFIX.$signIn->contact_id);

        return $user instanceof ClientUser ? $user : null;
    }

    private function pending(string $token): ?PortalSignIn
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return PortalSignIn::query()
            ->where('token_hash', PortalSignIn::hash($token))
            ->whereNull('used_at')
            ->where('expires_at', '>', Date::now())
            ->first();
    }

    public static function identifierOf(?Authenticatable $user): ?int
    {
        return $user instanceof ClientUser ? $user->contactId : null;
    }
}
