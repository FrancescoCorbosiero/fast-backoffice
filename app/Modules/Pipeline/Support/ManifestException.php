<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use RuntimeException;

/**
 * The repository's pipeline.yml is missing or doesn't follow the contract
 * (docs/pipeline.md §4). The message is for the owner.
 */
final class ManifestException extends RuntimeException {}
