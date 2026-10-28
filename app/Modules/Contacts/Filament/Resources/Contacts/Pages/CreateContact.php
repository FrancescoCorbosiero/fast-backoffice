<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Pages;

use App\Modules\Contacts\Actions\CreateContact as CreateContactAction;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use App\Modules\Contacts\Models\Contact;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $contact = app(CreateContactAction::class)->create(ContactInput::fromArray($data));

        return Contact::query()->findOrFail($contact->id);
    }

    protected function getRedirectUrl(): string
    {
        return ContactResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
