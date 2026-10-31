<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Pages;

use App\Modules\Contacts\Actions\UpdateContact;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Contact) {
            throw new LogicException('The contact form edits contacts.');
        }

        app(UpdateContact::class)->update($record->id, ContactInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return ContactResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
