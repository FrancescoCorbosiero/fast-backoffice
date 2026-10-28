<?php

declare(strict_types=1);

namespace Tests\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Reads the module tree and the layer table, and finds the references
 * between modules by scanning each file's `use` statements and
 * fully-qualified names.
 */
final readonly class Modules
{
    private const string ROOT = 'App\\Modules\\';

    /**
     * @param  list<string>  $order  modules in layer order
     * @param  list<string>  $publicFolders
     */
    private function __construct(
        private array $order,
        private array $publicFolders,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{layers: array<int, list<string>>, public_folders: list<string>} $config */
        $config = require self::basePath('config/modules.php');

        $order = [];
        ksort($config['layers']);

        foreach ($config['layers'] as $modules) {
            foreach ($modules as $module) {
                $order[] = $module;
            }
        }

        return new self($order, $config['public_folders']);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->order;
    }

    /**
     * @return list<string>
     */
    public function sortedNames(): array
    {
        $names = $this->order;
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public static function directories(): array
    {
        $directories = array_map('basename', glob(self::basePath('app/Modules/*'), GLOB_ONLYDIR) ?: []);
        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    public static function registeredProviders(): array
    {
        /** @var list<class-string> $providers */
        $providers = require self::basePath('bootstrap/providers.php');

        return array_values(array_filter($providers, fn (string $provider): bool => str_starts_with($provider, self::ROOT)));
    }

    /**
     * References from one module to a private folder of another:
     * "File → Class" strings, empty when the rule holds.
     *
     * @return list<string>
     */
    public function privateUses(): array
    {
        $violations = [];

        foreach ($this->references() as [$file, $module, $class, $target]) {
            if ($target !== $module && ! $this->isPublic($target, $class)) {
                $violations[] = "{$file} → {$class}";
            }
        }

        return $violations;
    }

    /**
     * References to a module in a higher layer, or later in the same layer.
     *
     * @return list<string>
     */
    public function upwardUses(): array
    {
        $violations = [];

        foreach ($this->references() as [$file, $module, $class, $target]) {
            if ($target === $module) {
                continue;
            }

            $position = array_search($module, $this->order, true);
            $targetPosition = array_search($target, $this->order, true);

            if ($position === false || $targetPosition === false || $targetPosition > $position) {
                $violations[] = "{$file} → {$class}";
            }
        }

        return $violations;
    }

    /**
     * Files in the given module folder that reference any Models namespace.
     *
     * @return list<string>
     */
    public function modelUsesIn(string $folder): array
    {
        $violations = [];

        foreach ($this->order as $module) {
            foreach (self::phpFiles(self::basePath("app/Modules/{$module}/{$folder}")) as $file) {
                if (preg_match('/\\\\Models\\\\/', (string) file_get_contents($file)) === 1) {
                    $violations[] = self::relative($file);
                }
            }
        }

        return $violations;
    }

    /**
     * Classes in Data folders that aren't final readonly.
     *
     * @return list<string>
     */
    public function nonReadonlyData(): array
    {
        $violations = [];

        foreach ($this->order as $module) {
            foreach (self::phpFiles(self::basePath("app/Modules/{$module}/Data")) as $file) {
                $class = self::ROOT.$module.'\\Data\\'.basename($file, '.php');

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (! $reflection->isFinal() || ! $reflection->isReadOnly()) {
                    $violations[] = $class;
                }
            }
        }

        return $violations;
    }

    /**
     * "File:line" for every call of one of the methods under app/, except
     * under the given path.
     *
     * @return list<string>
     */
    public static function methodCalls(string ...$methods): array
    {
        $except = null;
        $names = [];

        foreach ($methods as $name => $method) {
            if ($name === 'except') {
                $except = $method;
            } else {
                $names[] = preg_quote($method, '/');
            }
        }

        $pattern = '/(?:->|::)(?:'.implode('|', $names).')\(/';
        $calls = [];

        foreach (self::phpFiles(self::basePath('app')) as $file) {
            if ($except !== null && str_starts_with(self::relative($file), $except)) {
                continue;
            }

            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $calls[] = self::relative($file).':'.($number + 1);
                }
            }
        }

        return $calls;
    }

    /**
     * Every reference from a module file to a module class:
     * [file, module, class, target module].
     *
     * @return list<array{string, string, string, string}>
     */
    private function references(): array
    {
        $references = [];

        foreach ($this->order as $module) {
            foreach (self::phpFiles(self::basePath("app/Modules/{$module}")) as $file) {
                $contents = (string) file_get_contents($file);
                preg_match_all('/App\\\\Modules\\\\([A-Z][A-Za-z]+)\\\\([A-Za-z\\\\]+)/', $contents, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $references[] = [self::relative($file), $module, 'App\\Modules\\'.$match[1].'\\'.$match[2], $match[1]];
                }
            }
        }

        return $references;
    }

    private function isPublic(string $module, string $class): bool
    {
        $inside = substr($class, strlen(self::ROOT.$module.'\\'));

        foreach ($this->publicFolders as $folder) {
            if (str_starts_with($inside, $folder.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return str_replace(self::basePath('').'/', '', $path);
    }

    private static function basePath(string $path): string
    {
        $base = realpath(dirname(__DIR__, 2));

        if ($base === false) {
            throw new RuntimeException('Cannot find the project root.');
        }

        return $path === '' ? $base : $base.'/'.$path;
    }
}
