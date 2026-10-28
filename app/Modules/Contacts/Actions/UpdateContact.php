<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\ContactValidator;
use App\Modules\Contacts\ContactWriter;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Events\ContactUpdated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

final readonly class UpdateContact
{
    public function __construct(
        private ConnectionInterface $db,
        private ContactValidator $validator,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function update(int $contactId, ContactInput $input): ContactData
    {
        $this->validator->validate($input, ignoreId: $contactId);

        return $this->db->transaction(function () use ($contactId, $input): ContactData {
            $contact = ContactWriter::liveOrFail($contactId);
            $contact->fill(ContactWriter::attributes($input));
            $changed = array_keys($contact->getDirty());
            $contact->save();
            $data = $contact->toData();

            if ($changed !== []) {
                $this->audit->record('contacts.contact.updated', $data->reference(), ['changed' => $changed]);
                $this->events->dispatch(new ContactUpdated($data->id));
            }

            return $data;
        });
    }
}
