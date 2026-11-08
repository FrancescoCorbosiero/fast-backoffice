<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Livewire
|--------------------------------------------------------------------------
|
| Only what differs from Livewire's defaults. The panel's uploads go
| through Livewire's temporary uploads, one request per file: the rule
| must let a change-request photo of pipeline.attachments.max_mb through
| (Livewire's own default is 12 MB). The rules apply to every upload
| field in the panel; each field adds its own types and limits.
|
*/

return [

    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:25600'],
        'max_upload_time' => 15,
    ],

];
