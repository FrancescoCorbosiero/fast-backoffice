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
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A client asks for a change on one of their websites. The request is
 * recorded, queued, and the job is dispatched once the row is committed.
 */
final readonly class SubmitChangeRequest
{
    public const int MAX_LENGTH = 4000;

    public function __construct(
        private ConnectionInterface $db,
        private FindContact $contacts,
        private Websites $websites,
        private WebsitePipelines $pipelines,
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

        $text = trim($input->requestText);
        if ($text === '') {
            throw ValidationException::withMessages(['request_text' => 'Scrivi cosa vuoi cambiare.']);
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['request_text' => 'La richiesta è troppo lunga: al massimo '.self::MAX_LENGTH.' caratteri.']);
        }

        $data = $this->db->transaction(function () use ($text, $contact, $website): ChangeRequestData {
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
            $this->audit->record('pipeline.change_request.received', $request->toData()->reference(), ['website_id' => $website->id, 'contact_id' => $contact->id]);

            $request->moveTo(ChangeRequestStatus::Queued);
            $this->audit->record('pipeline.change_request.queued', $request->toData()->reference());

            return $request->toData();
        });

        $this->events->dispatch(new ChangeRequestSubmitted($data->id, $data->websiteId, $data->contactId));
        $this->events->dispatch(new ChangeRequestQueued($data->id, $data->websiteId, $data->contactId));
        $this->bus->dispatch(new RunStaticSiteRequest($data->id));

        return $data;
    }
}
