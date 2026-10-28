<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Support\ModuleServiceProvider;

class FilesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Files';
    }
}
