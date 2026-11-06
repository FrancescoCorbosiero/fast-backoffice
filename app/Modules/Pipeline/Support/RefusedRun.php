<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use RuntimeException;

/**
 * A run that ended without a preview, with a message for the owner (the
 * exception message: raw, for the log) and one for the client.
 */
final class RefusedRun extends RuntimeException
{
    public function __construct(string $message, public readonly string $clientMessage)
    {
        parent::__construct($message);
    }
}
