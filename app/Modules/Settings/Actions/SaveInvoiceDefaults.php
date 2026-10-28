<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use Illuminate\Database\ConnectionInterface;

final readonly class SaveInvoiceDefaults
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private GetNumberingSeries $numberingSeries,
    ) {}

    public function save(InvoiceDefaults $defaults): void
    {
        $series = $this->numberingSeries->get();
        $series->get($defaults->invoiceSeries);
        $series->get($defaults->creditNoteSeries);

        $this->db->transaction(function () use ($defaults): void {
            Setting::write(GetInvoiceDefaults::KEY, $defaults->toArray());
            $this->audit->record('settings.invoice_defaults.saved', details: $defaults->toArray());
        });
    }
}
