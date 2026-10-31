<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Actions\RebuildPaidAmounts;
use Illuminate\Console\Command;

class RebuildPaidAmountsCommand extends Command
{
    protected $signature = 'invoicing:rebuild-paid';

    protected $description = 'Recompute every cached paid amount from the cash movements';

    public function handle(RebuildPaidAmounts $rebuild): int
    {
        $fixed = $rebuild->rebuild();

        $this->info($fixed === 0 ? 'Every cached paid amount matched the movements.' : "Fixed {$fixed} cached paid amount(s).");

        return self::SUCCESS;
    }
}
