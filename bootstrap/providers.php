<?php

declare(strict_types=1);

/*
| Module providers come first, in layer order (config/modules.php), so their
| Filament plugins are tagged before the panel provider collects them.
*/

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Files\FilesServiceProvider::class,
    App\Modules\Audit\AuditServiceProvider::class,
    App\Modules\Settings\SettingsServiceProvider::class,
    App\Modules\Scheduling\SchedulingServiceProvider::class,
    App\Modules\Contacts\ContactsServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
