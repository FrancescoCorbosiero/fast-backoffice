<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http;

use App\Modules\Pipeline\Auth\ClientUser;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use App\Modules\Pipeline\Support\AttachmentStore;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only way to see an attachment (docs/pipeline.md §10): through the
 * owner's panel or the client's portal, each route with its own guard,
 * both authorised by ChangeRequestPolicy::view. There is no file URL. A
 * denial, an attachment of another request and a removed file are all a
 * 404, so a client can't even tell another client's photo exists.
 */
final class AttachmentsController
{
    public function __construct(
        private readonly Gate $gate,
        private readonly AttachmentStore $store,
    ) {}

    /**
     * GET /area-clienti/richieste/{id}/foto/{attachment}, on the portal guard.
     */
    public function portal(Request $request, int $id, int $attachment): Response
    {
        $client = $request->user('portal');

        return $this->respond($client instanceof ClientUser ? $client : null, $id, $attachment);
    }

    /**
     * GET /admin/change-requests/{record}/attachments/{attachment}, behind
     * the panel's login: the user of the panel's own guard (the owner's
     * model), never a portal client, whom the policy would treat as the
     * owner if a guard were mixed up.
     */
    public function owner(int $record, int $attachment): Response
    {
        return $this->respond(Filament::auth()->user(), $record, $attachment);
    }

    private function respond(?Authenticatable $user, int $requestId, int $attachmentId): Response
    {
        $request = ChangeRequest::query()->find($requestId);
        if ($user === null || $request === null || ! $this->gate->forUser($user)->allows('view', $request)) {
            throw new NotFoundHttpException;
        }

        $attachment = ChangeRequestAttachment::query()->whereKey($attachmentId)->where('change_request_id', $request->id)->first();
        if ($attachment === null || $attachment->removed_at !== null || ! $this->store->exists($attachment->stored_path)) {
            throw new NotFoundHttpException;
        }

        return $this->store->response($attachment->stored_path, $attachment->mime, $attachment->toData()->workspaceName());
    }
}
