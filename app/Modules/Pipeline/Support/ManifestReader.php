<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\PipelineManifest;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * pipeline.yml → PipelineManifest, checked against the contract: a build
 * command, an output folder inside the repository, at least one editable
 * path that the path policy would accept, an optional deploy command.
 */
final readonly class ManifestReader
{
    public const string FILE = 'pipeline.yml';

    /**
     * @throws ManifestException
     */
    public function read(string $yaml): PipelineManifest
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new ManifestException('pipeline.yml non è YAML valido: '.$exception->getMessage());
        }

        if (! is_array($data)) {
            throw new ManifestException('pipeline.yml deve contenere le chiavi build, output_dir ed editable_paths.');
        }

        $build = $this->command($data, 'build');
        if ($build === null) {
            throw new ManifestException('pipeline.yml: manca il comando "build".');
        }

        $outputDir = isset($data['output_dir']) && is_string($data['output_dir']) ? PathPolicy::normalise(rtrim($data['output_dir'], '/')) : '';
        if ($outputDir === '' || $outputDir === '.' || str_starts_with($outputDir, '/') || preg_match('#(^|/)\.\.(/|$)#', $outputDir) === 1) {
            throw new ManifestException('pipeline.yml: "output_dir" deve essere una cartella dentro il repository.');
        }

        $paths = $data['editable_paths'] ?? null;
        if (! is_array($paths) || $paths === []) {
            throw new ManifestException('pipeline.yml: "editable_paths" deve elencare almeno un percorso.');
        }
        $editable = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new ManifestException('pipeline.yml: ogni voce di "editable_paths" deve essere un percorso.');
            }
            $path = rtrim(PathPolicy::normalise($path), '/');
            $reason = PathPolicy::refuseAlways($path);
            if ($reason !== null) {
                throw new ManifestException("pipeline.yml: \"{$path}\" non può essere modificabile ({$reason}).");
            }
            $editable[] = $path;
        }

        return new PipelineManifest($build, $outputDir, array_values(array_unique($editable)), $this->command($data, 'deploy'));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function command(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
