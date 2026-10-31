<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Kernel\Dates\Frequency;
use App\Modules\Invoicing\Actions\CreateRecurringInvoice;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\RecurringInvoiceInput;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A website's subscription is a recurring invoice created through
 * Invoicing (design.md §5.5); the website keeps the schedule's reference.
 */
final readonly class StartSubscription
{
    public function __construct(
        private ConnectionInterface $db,
        private CreateRecurringInvoice $recurring,
    ) {}

    /**
     * @param  list<LineInput>  $lines
     *
     * @throws ValidationException
     * @throws LogicException when the website already has a subscription
     */
    public function start(int $websiteId, array $lines, Frequency $frequency, CarbonImmutable $startOn, bool $autoIssue = false, ?int $paymentTermsDays = null): WebsiteData
    {
        return $this->db->transaction(function () use ($websiteId, $lines, $frequency, $startOn, $autoIssue, $paymentTermsDays): WebsiteData {
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);

            if ($website->subscription_id !== null) {
                throw new LogicException("Website {$websiteId} already has a subscription: stop it first.");
            }

            $schedule = $this->recurring->create(new RecurringInvoiceInput(
                name: 'Canone sito '.$website->name,
                contactId: $website->contact_id,
                frequency: $frequency,
                startedOn: $startOn,
                lines: $lines,
                paymentTermsDays: $paymentTermsDays,
                autoIssue: $autoIssue,
                notes: 'Sito '.$website->url,
            ));

            $website->forceFill(['subscription_id' => $schedule->id])->save();

            return $website->toData();
        });
    }
}
