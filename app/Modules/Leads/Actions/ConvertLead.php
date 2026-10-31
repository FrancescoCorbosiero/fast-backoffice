<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Actions\CreateContact;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Leads\Data\ConversionInput;
use App\Modules\Leads\Data\ConversionResult;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Events\LeadConverted;
use App\Modules\Leads\Models\Lead;
use App\Modules\Quotes\Actions\CreateQuote;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Websites\Actions\CreateWebsite;
use App\Modules\Websites\Data\WebsiteInput;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Converting a lead creates the contact, and optionally a quote and a
 * website, in one transaction (design.md §4.4): use cases call down.
 */
final readonly class ConvertLead
{
    public function __construct(
        private ConnectionInterface $db,
        private FindContact $contacts,
        private CreateContact $createContact,
        private CreateQuote $createQuote,
        private CreateWebsite $createWebsite,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException when a module refuses what it is asked to create
     * @throws LogicException when the lead is no longer open
     */
    public function convert(int $leadId, ConversionInput $input): ConversionResult
    {
        return $this->db->transaction(function () use ($leadId, $input): ConversionResult {
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);

            if (! $lead->status->isOpen()) {
                throw new LogicException("Lead {$leadId} is {$lead->status->value}: only an open lead is converted.");
            }

            $contactCreated = false;
            if ($input->existingContactId !== null) {
                $contact = $this->contacts->byId($input->existingContactId)
                    ?? throw ValidationException::withMessages(['existing_contact_id' => 'Il contatto scelto non esiste.']);
            } else {
                $contact = $this->createContact->create($this->contactInput($lead, $input));
                $contactCreated = true;
            }

            $quoteId = null;
            if ($input->wantsQuote()) {
                $quoteId = $this->createQuote->create(new QuoteInput(
                    contactId: $contact->id,
                    lines: $input->quoteLines,
                    validUntil: $input->quoteValidUntil,
                    subject: $input->quoteSubject,
                ))->id;
            }

            $websiteId = null;
            if ($input->wantsWebsite()) {
                $websiteId = $this->createWebsite->create(new WebsiteInput(
                    contactId: $contact->id,
                    name: $input->websiteName ?? ($lead->company ?? $lead->name),
                    url: trim((string) $input->websiteUrl),
                ))->id;
            }

            $lead->forceFill([
                'status' => LeadStatus::Won,
                'contact_id' => $contact->id,
                'quote_id' => $quoteId,
                'website_id' => $websiteId,
                'converted_at' => Date::now(),
                'next_follow_up_on' => null,
                'lost_reason' => null,
            ])->save();

            $data = $lead->toData();
            $this->audit->record('leads.lead.converted', $data->reference(), ['contact_id' => $contact->id, 'contact_created' => $contactCreated, 'quote_id' => $quoteId, 'website_id' => $websiteId]);
            $this->events->dispatch(new LeadConverted($lead->id, $contact->id, $quoteId, $websiteId));

            return new ConversionResult($data, $contact->id, $contactCreated, $quoteId, $websiteId);
        });
    }

    private function contactInput(Lead $lead, ConversionInput $input): ContactInput
    {
        $asCompany = $input->asCompany || ($lead->company !== null && trim($lead->company) !== '');
        $parts = preg_split('/\s+/', trim($lead->name), 2) ?: [$lead->name];

        return new ContactInput(
            kind: $asCompany ? ContactKind::Company : ContactKind::Individual,
            roles: [ContactRole::Client],
            firstName: $asCompany ? null : $parts[0],
            lastName: $asCompany ? null : ($parts[1] ?? null),
            businessName: $asCompany ? ($lead->company !== null && trim($lead->company) !== '' ? $lead->company : $lead->name) : null,
            email: $lead->email,
            phone: $lead->phone,
            notes: $lead->notes === null ? null : 'Dal lead: '.$lead->notes,
        );
    }
}
