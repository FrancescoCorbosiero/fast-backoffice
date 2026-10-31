<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http;

use App\Modules\Mail\Actions\Unsubscribe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * GET shows a page with one button; POST does it (RFC 8058: a one-click
 * POST with List-Unsubscribe=One-Click, or the page's form). GET never
 * changes anything.
 */
final class UnsubscribeController
{
    public function show(string $token, Unsubscribe $unsubscribe): View|Response
    {
        $recipient = $unsubscribe->find($token);
        if ($recipient === null) {
            return new Response('Link non valido.', 404);
        }

        return view('mail.unsubscribe', ['done' => $recipient->unsubscribedAt !== null, 'email' => $recipient->email, 'action' => route('mail.unsubscribe', ['token' => $token])]);
    }

    public function confirm(string $token, Unsubscribe $unsubscribe): View|Response
    {
        $recipient = $unsubscribe->unsubscribe($token);
        if ($recipient === null) {
            return new Response('Link non valido.', 404);
        }

        return view('mail.unsubscribe', ['done' => true, 'email' => $recipient->email, 'action' => route('mail.unsubscribe', ['token' => $token])]);
    }
}
