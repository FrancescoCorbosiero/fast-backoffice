<?php

declare(strict_types=1);

return [

    /*
    | The single currency the studio bills in. Money carries its currency
    | anyway; this is the default new amounts get.
    */
    'currency' => 'EUR',

    /*
    | Where stored files (PDFs, XML, PEC envelopes) live: the name of a disk
    | in config/filesystems.php. "files" is the S3-compatible bucket; locally
    | "files-local" keeps them under storage/app/private/files.
    */
    'files_disk' => env('FILES_DISK', 'local') === 's3' ? 'files' : 'files-local',

];
