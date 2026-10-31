<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PaymentRequest;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Models\SupplierInvoice;

/**
 * Documents follow a merged contact; issued ones keep their frozen snapshot.
 */
final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        foreach ([Invoice::class, PaymentRequest::class, RecurringInvoice::class, SupplierInvoice::class] as $model) {
            $model::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
        }
    }
}
