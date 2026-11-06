<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Policies;

use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The owner sees and decides everything; a client (signed in to the
 * portal) only the requests of their own contact.
 */
final class ChangeRequestPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, ChangeRequest $request): bool
    {
        return $this->owns($user, $request);
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Onboarding is the owner's call: the client sees it, never decides it.
     */
    public function decide(Authenticatable $user, ChangeRequest $request): bool
    {
        if ($user instanceof ClientUser && $request->kind === ChangeRequestKind::Onboarding) {
            return false;
        }

        return $this->owns($user, $request);
    }

    public function update(Authenticatable $user, ChangeRequest $request): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, ChangeRequest $request): bool
    {
        return false;
    }

    private function owns(Authenticatable $user, ChangeRequest $request): bool
    {
        if ($user instanceof ClientUser) {
            return $user->contactId === $request->contact_id;
        }

        return true;
    }
}
