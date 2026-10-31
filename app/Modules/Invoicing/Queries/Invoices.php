<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Kernel\Dates\Months;
use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

final readonly class Invoices
{
    private string $currency;

    public function __construct(Repository $config)
    {
        $currency = $config->get('backoffice.currency');
        $this->currency = is_string($currency) ? $currency : 'EUR';
    }

    /**
     * @return list<InvoiceData> newest first
     */
    public function forContact(int $contactId, int $limit = 100): array
    {
        return $this->map(Invoice::query()->where('contact_id', $contactId)->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get()->all());
    }

    /**
     * Issued invoices that haven't reached SdI, oldest first: the 12-day
     * countdown (sdi.md §3).
     *
     * @return list<InvoiceData>
     */
    public function toSend(): array
    {
        return $this->map(Invoice::query()->where('status', InvoiceStatus::Issued->value)->orderBy('issued_on')->orderBy('id')->get()->all());
    }

    /**
     * What clients still owe on issued invoices, by due date.
     *
     * @return list<InvoiceData>
     */
    public function receivables(): array
    {
        return $this->map(Invoice::query()->outstanding()->where('type', DocumentType::Invoice->value)->orderBy('due_on')->orderBy('id')->get()->all());
    }

    /**
     * The stamp duty the Agenzia will compute for a quarter: how many
     * invoices owed it and the amount (sdi.md §7).
     *
     * @return array{count: int, total: Money}
     */
    public function stampDutyQuarter(int $year, int $quarter): array
    {
        $from = CarbonImmutable::parse(sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1));
        $to = Months::add($from, 2)->endOfMonth();

        $count = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value, InvoiceStatus::Rejected->value])
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("(totals->>'stamp_duty_due')::boolean = true")
            ->count();

        return ['count' => $count, 'total' => new Money($count * 200, $this->currency)];
    }

    /**
     * @param  array<int, Invoice>  $invoices
     * @return list<InvoiceData>
     */
    private function map(array $invoices): array
    {
        return array_values(array_map(fn (Invoice $invoice): InvoiceData => $invoice->toData(), $invoices));
    }
}
