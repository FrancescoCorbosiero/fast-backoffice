<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http;

use App\Modules\Mail\Actions\ProcessSesNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SesNotificationController
{
    public function __invoke(Request $request, ProcessSesNotification $process): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (! is_array($body)) {
            return new JsonResponse(['outcome' => 'rejected'], 400);
        }

        $message = [];
        foreach ($body as $key => $value) {
            $message[(string) $key] = $value;
        }

        $outcome = $process->process($message);

        return new JsonResponse(['outcome' => $outcome], $outcome === 'rejected' ? 401 : 200);
    }
}
