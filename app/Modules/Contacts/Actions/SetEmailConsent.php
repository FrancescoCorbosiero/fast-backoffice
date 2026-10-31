<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\ContactWriter;
use App\Modules\Contacts\Data\ContactData;
use Illuminate\Database\ConnectionInterface;

/**
 * Records whether the contact agreed to receive the studio's emails.
 */
final readonly class SetEmailConsent
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    public function set(int $contactId, bool $consent, string $source): ContactData
    {
        return $this->db->transaction(function () use ($contactId, $consent, $source): ContactData {
            $contact = ContactWriter::liveOrFail($contactId);

            if ($contact->email_consent !== $consent) {
                $contact->forceFill(['email_consent' => $consent])->save();
                $this->audit->record('contacts.email.consent_changed', $contact->toData()->reference(), ['consent' => $consent, 'source' => $source]);
            }

            return $contact->toData();
        });
    }
}
