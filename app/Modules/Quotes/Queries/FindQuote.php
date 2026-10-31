<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Queries;

use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Models\Quote;

final readonly class FindQuote
{
    public function byId(int $id): ?QuoteData
    {
        return Quote::query()->find($id)?->toData();
    }
}
