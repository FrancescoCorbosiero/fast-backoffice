<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use App\Modules\Cash\Enums\AccountType;
use Carbon\CarbonImmutable;

final readonly class AccountData
{
    public function __construct(
        public int $id,
        public string $name,
        public AccountType $type,
        public ?string $iban,
        public string $currency,
        public Money $openingBalance,
        public CarbonImmutable $openedOn,
        public bool $isActive,
        public Money $balance,
    ) {}
}
