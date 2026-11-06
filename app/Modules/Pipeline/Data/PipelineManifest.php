<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

/**
 * The repository's pipeline.yml, written at onboarding and approved by the
 * owner (docs/pipeline.md §4): how the site is built, where the output
 * lands, which paths a change request may touch, and how it is deployed.
 */
final readonly class PipelineManifest
{
    /**
     * @param  list<string>  $editablePaths  directory prefixes or files, relative to the repository
     */
    public function __construct(
        public string $build,
        public string $outputDir,
        public array $editablePaths,
        public ?string $deploy = null,
    ) {}
}
