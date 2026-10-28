<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Modules and their layers (design.md §4.1)
|--------------------------------------------------------------------------
|
| A module may depend on modules in lower layers, and on modules listed
| before it in its own layer. Never upward, never in a cycle. The
| architecture tests read this table; add a module here when it's built.
|
*/

return [

    'layers' => [
        0 => ['Files', 'Audit', 'Settings', 'Scheduling'],
        1 => ['Contacts'],
        2 => ['Cash'],
    ],

    /*
    | Folders other modules may use (design.md §4.3). Everything else in a
    | module is private to it.
    */
    'public_folders' => ['Actions', 'Queries', 'Data', 'Events', 'Enums', 'Contracts', 'Filament\\Components'],

];
