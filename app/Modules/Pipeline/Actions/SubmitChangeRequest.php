<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestQueued;
use App\Modules\Pipeline\Events\ChangeRequestSubmitted;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Pipeline\Support\AttachmentIntake;
use App\Modules\Pipeline\Support\AttachmentStore;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A client asks for a change on one of their websites, with photos if
 * they like (docs/pipeline.md §10). The text and the photos are checked
 * together, the photos cleaned and stored, the request recorded and
 * queued, and the job dispatched once the row is committed.
 */
final readonly class SubmitChangeRequest
{
    public const int MAX_LENGTH = 4000;

    public function __construct(
        private ConnectionInterface $db,
        private FindContact $contacts,
        private Websites $websites,
        private WebsitePipelines $pipelines,
        private AttachmentIntake $intake,
        private AttachmentStore $store,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    /**
     * @throws ValidationException
     */
    public function submit(ChangeRequestInput $input): ChangeRequestData
    {
        $contact = $this->contacts->byId($input->contactId);
        if ($contact === null || ! $contact->hasRole(ContactRole::Client)) {
            throw ValidationException::withMessages(['contact_id' => 'Scegli il cliente.']);
        }

        $website = $this->websites->find($input->websiteId);
        if ($website === null || $website->contactId !== $contact->id) {
            throw ValidationException::withMessages(['website_id' => 'Scegli uno dei siti del cliente.']);
        }

        $pipeline = $this->pipelines->forWebsite($website->id);
        if ($pipeline === null || ! $pipeline->isReady()) {
            throw ValidationException::withMessages(['website_id' => 'Questo sito non riceve ancora richieste di modifica.']);
        }

        $errors = [];
        $text = trim($input->requestText);
        if ($text === '') {
            $errors['request_text'] = ['Scrivi cosa vuoi cambiare.'];
        } elseif (mb_strlen($text) > self::MAX_LENGTH) {
            $errors['request_text'] = ['La richiesta è troppo lunga: al massimo '.self::MAX_LENGTH.' caratteri.'];
        }

        [$copies, $copyErrors] = $this->copies($input->attachmentsFrom, $contact->id, $website->id);
        $prepared = $this->intake->prepare($input->attachments, count($copies), array_sum(array_map(fn (ChangeRequestAttachment $copy): int => $copy->size_bytes, $copies)));
        $attachmentErrors = [...$copyErrors, ...$prepared['errors']];
        if ($attachmentErrors !== []) {
            $errors['attachments'] = $attachmentErrors;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // The files first, the rows after: a failure on the way deletes
        // whatever was stored, so no file is left without its row.
        $rows = [];
        try {
            foreach ($copies as $copy) {
                $bytes = $this->store->get($copy->stored_path);
                if (! hash_equals($copy->sha256, hash('sha256', $bytes))) {
                    throw ValidationException::withMessages(['attachments' => ["La foto «{$copy->original_name}» della richiesta di prima non è più leggibile: allegala di nuovo."]]);
                }
                $rows[] = ['original_name' => $copy->original_name, 'stored_path' => $this->store->put($bytes, $copy->mime), 'mime' => $copy->mime, 'size_bytes' => $copy->size_bytes, 'sha256' => $copy->sha256, 'width' => $copy->width, 'height' => $copy->height];
            }
            foreach ($prepared['images'] as ['name' => $name, 'image' => $image]) {
                $rows[] = ['original_name' => $name, 'stored_path' => $this->store->put($image->bytes, $image->format), 'mime' => $image->format, 'size_bytes' => strlen($image->bytes), 'sha256' => hash('sha256', $image->bytes), 'width' => $image->width, 'height' => $image->height];
            }

            $data = $this->db->transaction(function () use ($text, $contact, $website, $rows): ChangeRequestData {
                $request = ChangeRequest::query()->create([
                    'contact_id' => $contact->id,
                    'website_id' => $website->id,
                    'client_name' => mb_substr($contact->displayName, 0, 160),
                    'website_name' => mb_substr($website->name, 0, 120),
                    'kind' => ChangeRequestKind::Change,
                    'status' => ChangeRequestStatus::Received,
                    'request_text' => $text,
                    'branch' => 'req/pending',
                    'preview_slug' => mb_strtolower((string) Str::ulid()),
                ]);
                $request->forceFill(['branch' => 'req/'.$request->id])->save();
                foreach ($rows as $index => $row) {
                    $request->attachments()->create([...$row, 'position' => $index + 1]);
                }
                $this->audit->record('pipeline.change_request.received', $request->toData()->reference(), ['website_id' => $website->id, 'contact_id' => $contact->id, 'attachments' => count($rows)]);

                $request->moveTo(ChangeRequestStatus::Queued);
                $this->audit->record('pipeline.change_request.queued', $request->toData()->reference());

                return $request->load('attachments')->toData();
            });
        } catch (Throwable $exception) {
            foreach ($rows as $row) {
                $this->store->delete($row['stored_path']);
            }

            throw $exception;
        }

        $this->events->dispatch(new ChangeRequestSubmitted($data->id, $data->websiteId, $data->contactId));
        $this->events->dispatch(new ChangeRequestQueued($data->id, $data->websiteId, $data->contactId));
        $this->bus->dispatch(new RunStaticSiteRequest($data->id));

        return $data;
    }

    /**
     * The photos of an earlier request that are still there, for "Ripeti
     * richiesta": only of the same client and website, so one mis-click
     * never puts a client's photos in another client's repository.
     *
     * @return array{0: list<ChangeRequestAttachment>, 1: list<string>}
     */
    private function copies(?int $requestId, int $contactId, int $websiteId): array
    {
        if ($requestId === null) {
            return [[], []];
        }

        $source = ChangeRequest::query()->with('attachments')->find($requestId);
        if ($source === null || $source->contact_id !== $contactId || $source->website_id !== $websiteId) {
            return [[], ['Le foto da ripetere sono di un\'altra richiesta: si ripetono solo per lo stesso cliente e lo stesso sito.']];
        }

        return [array_values($source->attachments->filter(fn (ChangeRequestAttachment $attachment): bool => $attachment->removed_at === null)->all()), []];
    }
}
