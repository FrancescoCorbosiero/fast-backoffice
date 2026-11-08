<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages;

use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Data\AttachmentUpload;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Support\Filament\FormErrors;
use App\Support\Filament\PrefillsFromQuery;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Reached from the list, or from a client's or a website's page with the
 * client and the site already set: only the request, and its photos, are
 * left.
 */
class CreateChangeRequest extends CreateRecord
{
    use PrefillsFromQuery;

    protected static string $resource = ChangeRequestResource::class;

    protected function prefillable(): array
    {
        return ['contact_id', 'website_id'];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $files = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];
        $uploads = [];
        foreach ($files as $file) {
            // The field's state holds uploads only: anything else came from
            // the browser and is never read as a path.
            if (! $file instanceof TemporaryUploadedFile) {
                throw ValidationException::withMessages(['data.attachments' => 'Una delle foto non è arrivata come file: toglila e allegala di nuovo.']);
            }
            $uploads[] = AttachmentUpload::fromUploadedFile($file);
        }

        $input = ChangeRequestInput::fromArray($data);

        try {
            $request = app(SubmitChangeRequest::class)->submit(new ChangeRequestInput($input->contactId, $input->websiteId, $input->requestText, $uploads));
        } catch (ValidationException $exception) {
            FormErrors::onSchema($this->form, $exception);
        }

        // The originals still carry their metadata: they go as soon as the clean copies are stored.
        foreach ($files as $file) {
            $file->delete();
        }

        return ChangeRequest::query()->findOrFail($request->id);
    }

    protected function getRedirectUrl(): string
    {
        return ChangeRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
