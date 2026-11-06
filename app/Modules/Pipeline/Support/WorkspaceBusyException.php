<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use RuntimeException;

/**
 * Another job holds the website's checkout. Not a failure of the request:
 * the job goes back to the queue and tries again later.
 */
final class WorkspaceBusyException extends RuntimeException {}
