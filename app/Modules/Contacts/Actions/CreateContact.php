<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\ContactValidator;
use App\Modules\Contacts\ContactWriter;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Events\ContactCreated;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

final readonly class CreateContact
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
    public function create(ContactInput $input): ContactData
    {
        $this->validator->validate($input);

        return $this->db->transaction(function () use ($input): ContactData {
            $contact = Contact::query()->create(ContactWriter::attributes($input));
            $data = $contact->toData();

            $this->audit->record('contacts.contact.created', $data->reference(), ['name' => $data->displayName]);
            $this->events->dispatch(new ContactCreated($data->id));

            return $data;
        });
    }
}
