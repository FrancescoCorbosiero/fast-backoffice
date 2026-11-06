<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Portal;

use App\Modules\Pipeline\Actions\ApproveChangeRequest;
use App\Modules\Pipeline\Actions\RejectChangeRequest;
use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\ChangeRequests;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three screens of the portal: my requests, a new request, one
 * request with its preview and the decision. A client only ever sees
 * their own (ChangeRequestPolicy).
 */
final class RequestsController
{
    public function __construct(
        private readonly Gate $gate,
        private readonly ChangeRequests $requests,
        private readonly WebsitePipelines $pipelines,
    ) {}

    public function index(Request $request): View
    {
        $client = $this->client($request);

        return view('area-clienti.requests', [
            'client' => $client,
            'requests' => $this->requests->forContact($client->contactId),
            'hasReadyWebsites' => $this->pipelines->readyForContact($client->contactId) !== [],
        ]);
    }

    public function create(Request $request): View
    {
        $client = $this->client($request);

        return view('area-clienti.new-request', [
            'client' => $client,
            'websites' => $this->pipelines->readyForContact($client->contactId),
            'old' => ['website_id' => $request->old('website_id'), 'request_text' => $request->old('request_text')],
            'errors_' => $request->session()->get('errors'),
        ]);
    }

    public function store(Request $request, SubmitChangeRequest $submit): RedirectResponse
    {
        $client = $this->client($request);
        $websiteId = $request->input('website_id');
        $text = $request->input('request_text');

        try {
            $created = $submit->submit(new ChangeRequestInput($client->contactId, is_numeric($websiteId) ? (int) $websiteId : 0, is_string($text) ? $text : ''));
        } catch (ValidationException $exception) {
            return redirect()->route('portal.requests.create')->withInput()->withErrors($exception->validator);
        }

        return redirect()->route('portal.requests.show', ['id' => $created->id])->with('portal.status', 'Richiesta inviata: ci lavoriamo e ti avvisiamo quando l\'anteprima è pronta.');
    }

    public function show(Request $request, int $id): View
    {
        $client = $this->client($request);
        $model = $this->own($client, $id);

        return view('area-clienti.request', [
            'client' => $client,
            'request' => $model->toData(),
            'status' => $request->session()->get('portal.status'),
        ]);
    }

    public function approve(Request $request, int $id, ApproveChangeRequest $approve): RedirectResponse
    {
        $client = $this->client($request);
        $model = $this->own($client, $id, decide: true);

        try {
            $approve->approve($model->id, DecidedBy::Client, $client->contactId);
        } catch (LogicException) {
            return redirect()->route('portal.requests.show', ['id' => $id])->with('portal.status', 'Questa richiesta non aspetta più una decisione.');
        }

        return redirect()->route('portal.requests.show', ['id' => $id])->with('portal.status', 'Grazie! Pubblichiamo la modifica tra poco.');
    }

    public function reject(Request $request, int $id, RejectChangeRequest $reject): RedirectResponse
    {
        $client = $this->client($request);
        $model = $this->own($client, $id, decide: true);
        $reason = $request->input('reason');

        try {
            $reject->reject($model->id, DecidedBy::Client, is_string($reason) ? $reason : null, $client->contactId);
        } catch (LogicException) {
            return redirect()->route('portal.requests.show', ['id' => $id])->with('portal.status', 'Questa richiesta non aspetta più una decisione.');
        }

        return redirect()->route('portal.requests.show', ['id' => $id])->with('portal.status', 'Ricevuto: la modifica non verrà pubblicata. Se vuoi, fai una nuova richiesta spiegando meglio cosa ti serve.');
    }

    private function client(Request $request): ClientUser
    {
        $user = $request->user('portal');
        if (! $user instanceof ClientUser) {
            throw new NotFoundHttpException;
        }

        return $user;
    }

    /**
     * The request, only when it is the client's own: anything else is a 404.
     */
    private function own(ClientUser $client, int $id, bool $decide = false): ChangeRequest
    {
        $model = ChangeRequest::query()->find($id);
        if ($model === null || ! $this->gate->forUser($client)->allows($decide ? 'decide' : 'view', $model)) {
            throw new NotFoundHttpException;
        }

        return $model;
    }
}
