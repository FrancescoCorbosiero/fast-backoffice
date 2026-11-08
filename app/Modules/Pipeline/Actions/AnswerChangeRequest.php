<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Data\ConversationTurn;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestQueued;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The client answers what the agent asked (needs_input): the choices they
 * picked among the suggested options and anything they wrote become one
 * turn of the exchange, and the same request, photos and branch included,
 * is queued and run again with it.
 */
final readonly class AnswerChangeRequest
{
    public const int MAX_LENGTH = 2000;

    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    /**
     * @param  array<int, string>  $choices  question index → the option picked; anything not among the options is ignored
     *
     * @throws ValidationException when there is no answer at all, or it is too long
     * @throws LogicException when the request is not waiting for an answer
     */
    public function answer(int $changeRequestId, array $choices, string $note, ?int $answeringContactId = null): ChangeRequestData
    {
        $data = $this->db->transaction(function () use ($changeRequestId, $choices, $note, $answeringContactId): ChangeRequestData {
            $request = ChangeRequest::query()->with('attachments')->lockForUpdate()->findOrFail($changeRequestId);
            $question = $request->toData()->pendingQuestion();
            if ($question === null) {
                throw new LogicException("Change request {$request->id} is {$request->status->value}: it is not waiting for an answer.");
            }

            $lines = [];
            foreach ($question->questions as $index => $asked) {
                $picked = $choices[$index] ?? null;
                if ($picked !== null && in_array($picked, $asked->options, true)) {
                    $lines[] = $asked->question.' → '.$picked;
                }
            }
            $note = trim($note);
            if ($note !== '') {
                $lines[] = $note;
            }
            $text = implode("\n", $lines);
            if ($text === '') {
                throw ValidationException::withMessages(['answer' => 'Scegli una risposta o scrivi qualcosa.']);
            }
            if (mb_strlen($text) > self::MAX_LENGTH) {
                throw ValidationException::withMessages(['answer' => 'La risposta è troppo lunga: al massimo '.self::MAX_LENGTH.' caratteri.']);
            }

            $request->addTurn(new ConversationTurn(ConversationTurn::CLIENT, $text, Date::now()->toImmutable()));
            $request->moveTo(ChangeRequestStatus::Queued);
            $this->audit->record('pipeline.change_request.answered', $request->toData()->reference(), ['contact_id' => $answeringContactId]);

            return $request->toData();
        });

        $this->events->dispatch(new ChangeRequestQueued($data->id, $data->websiteId, $data->contactId));
        $this->bus->dispatch(new RunStaticSiteRequest($data->id));

        return $data;
    }
}
