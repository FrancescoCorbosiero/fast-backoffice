<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The PEC mailbox the app sends and receives SdI traffic with (sdi.md §2)
|--------------------------------------------------------------------------
|
| Hosts and credentials are secrets and live only here, read from the
| environment. The mailbox address, SdI's address and the switches are
| settings (Settings → SdI e PEC). Leave the hosts empty and the channel
| is simply not configured: export and "mark as sent" keep working.
|
*/

return [

    'smtp' => [
        'host' => env('PEC_SMTP_HOST'),
        'port' => (int) env('PEC_SMTP_PORT', 465),
        'encryption' => env('PEC_SMTP_ENCRYPTION', 'ssl'), // ssl, tls or none
        'username' => env('PEC_USERNAME'),
        'password' => env('PEC_PASSWORD'),
    ],

    'imap' => [
        'host' => env('PEC_IMAP_HOST'),
        'port' => (int) env('PEC_IMAP_PORT', 993),
        'encryption' => env('PEC_IMAP_ENCRYPTION', 'ssl'), // ssl, tls or none
        'username' => env('PEC_USERNAME'),
        'password' => env('PEC_PASSWORD'),
        'folder' => env('PEC_IMAP_FOLDER', 'INBOX'),
        'validate_cert' => (bool) env('PEC_IMAP_VALIDATE_CERT', true),
    ],

];
