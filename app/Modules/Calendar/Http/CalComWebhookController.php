<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http;

use App\Modules\Calendar\Actions\HandleCalComWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CalComWebhookController
{
    public function __invoke(Request $request, HandleCalComWebhook $handler): JsonResponse
    {
        $result = $handler->handle($request->getContent(), $request->header('X-Cal-Signature-256'));

        return new JsonResponse(['outcome' => $result->outcome, 'booking_id' => $result->bookingId], $result->accepted() ? 200 : 401);
    }
}
