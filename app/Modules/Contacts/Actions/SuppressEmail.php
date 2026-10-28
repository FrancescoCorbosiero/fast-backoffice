<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\ContactWriter;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Events\EmailSuppressed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * Stops email to a contact (bounce, complaint, unsubscribe, the owner's
 * choice). Idempotent: a contact already suppressed stays as it was and
 * no second event goes out.
 */
final readonly class SuppressEmail
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function suppress(int $contactId, string $reason): ContactData
    {
        return $this->db->transaction(function () use ($contactId, $reason): ContactData {
            $contact = ContactWriter::liveOrFail($contactId);

            if ($contact->email_suppressed_at !== null) {
                return $contact->toData();
            }

            $contact->forceFill(['email_suppressed_at' => Date::now(), 'email_suppression_reason' => $reason])->save();
            $data = $contact->toData();

            $this->audit->record('contacts.email.suppressed', $data->reference(), ['reason' => $reason]);
            $this->events->dispatch(new EmailSuppressed($data->id, $reason));

            return $data;
        });
    }

    /**
     * Lifts a suppression, for when the owner knows the address works again.
     */
    public function lift(int $contactId): ContactData
    {
        return $this->db->transaction(function () use ($contactId): ContactData {
            $contact = ContactWriter::liveOrFail($contactId);

            if ($contact->email_suppressed_at === null) {
                return $contact->toData();
            }

            $contact->forceFill(['email_suppressed_at' => null, 'email_suppression_reason' => null])->save();
            $data = $contact->toData();

            $this->audit->record('contacts.email.suppression_lifted', $data->reference());

            return $data;
        });
    }
}
