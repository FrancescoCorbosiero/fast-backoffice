<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cal.com (design.md §4.2, Calendar)
|--------------------------------------------------------------------------
|
| Bookings arrive through signed webhooks (X-Cal-Signature-256, an HMAC of
| the raw body with the webhook secret) and a periodic sync through the
| API. Both secrets live only here, read from the environment.
|
*/

return [

    'api_key' => env('CALCOM_API_KEY'),
    'api_url' => env('CALCOM_API_URL', 'https://api.cal.com/v2'),
    'api_version' => env('CALCOM_API_VERSION', '2024-08-13'),
    'webhook_secret' => env('CALCOM_WEBHOOK_SECRET'),

];
