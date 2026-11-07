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

/**
 * Most often reached to complete the fiscal identity before invoicing:
 * opened with ?return=<url of this app> (ContactLink::editUrl), saving
 * goes back there, to the draft the owner came from, instead of to the
 * contact page.
 */
class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    public ?string $returnUrl = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $return = request()->query('return');

        $this->returnUrl = is_string($return) && self::isOurs($return) ? $return : null;
    }

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
        return $this->returnUrl ?? ContactResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * A path of this app, or an absolute URL of this app: never somewhere else.
     */
    private static function isOurs(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return str_starts_with($url, rtrim(url('/'), '/').'/');
    }
}
