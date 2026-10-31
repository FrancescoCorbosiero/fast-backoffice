<?php

declare(strict_types=1);

/**
 * The module rules of design.md §4.3, enforced. A failure here means a
 * change reached into another module's internals or upward through the
 * layers: use that module's public folders (Actions, Queries, Data,
 * Events, Enums, Contracts, Filament\Components), or move the behaviour
 * to a contribution (§4.4).
 */

use Tests\Arch\Modules;

$modules = Modules::fromConfig();

arch('every module directory is listed in config/modules.php, and every listed module exists')
    ->expect(fn () => Modules::directories())
    ->toBe($modules->sortedNames());

arch('every module registers its service provider, in layer order, before the panel provider')
    ->expect(fn () => Modules::registeredProviders())
    ->toBe(array_map(fn (string $module): string => "App\\Modules\\{$module}\\{$module}ServiceProvider", $modules->names()));

arch('outside a module, only its public folders are used')
    ->expect(fn () => $modules->privateUses())
    ->toBe([]);

arch('dependencies follow the layers: never upward, never in a cycle')
    ->expect(fn () => $modules->upwardUses())
    ->toBe([]);

arch('the Kernel depends on no module')
    ->expect('App\Kernel')
    ->not->toUse(['App\Modules', 'App\Models', 'App\Providers', 'App\Support', 'App\Console', 'App\Http']);

arch('modules are reached only from the panel wiring and other modules, never from app-level code')
    ->expect(['App\Models', 'App\Console', 'App\Http', 'App\Support'])
    ->not->toUse('App\Modules');

arch('events carry ids and scalars, never models')
    ->expect(fn () => $modules->modelUsesIn('Events'))
    ->toBe([]);

arch('DTOs are final and readonly')
    ->expect(fn () => $modules->nonReadonlyData())
    ->toBe([]);

arch('runtime code reads config(), never env(): env() is null once the config is cached')
    ->expect('env')
    ->not->toBeUsedIn(['App', 'Database']);

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('dates are formatted with isoFormat(), never translatedFormat(), which reads PHP tokens')
    ->expect(fn () => Modules::methodCalls('translatedFormat'))
    ->toBe([]);

arch('month arithmetic goes through the Kernel, so it never overflows or drifts')
    ->expect(fn () => Modules::methodCalls('addMonth', 'addMonths', 'subMonth', 'subMonths', 'addMonthsNoOverflow', 'addMonthNoOverflow', 'subMonthsNoOverflow', 'addMonthsWithOverflow', 'addMonthWithOverflow', except: 'app/Kernel/Dates'))
    ->toBe([]);

arch('money never travels as a float')
    ->expect(fn () => Modules::methodCalls('toMajor', 'fromMajor'))
    ->toBe([]);

arch('Kernel value objects are final')
    ->expect('App\Kernel')
    ->classes()
    ->toBeFinal();
