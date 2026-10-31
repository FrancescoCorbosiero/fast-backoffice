<?php

declare(strict_types=1);

namespace App\Modules\Settings\Queries;

use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Models\Setting;

final readonly class GetInvoiceDefaults
{
    public const string KEY = 'invoice_defaults';

    public function get(): InvoiceDefaults
    {
        $stored = Setting::read(self::KEY);

        return $stored === null ? new InvoiceDefaults : InvoiceDefaults::fromArray($stored);
    }
}
