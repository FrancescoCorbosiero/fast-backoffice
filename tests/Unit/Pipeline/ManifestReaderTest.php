<?php

declare(strict_types=1);

use App\Modules\Pipeline\Support\ManifestException;
use App\Modules\Pipeline\Support\ManifestReader;

it('reads the contract: build, output_dir, editable_paths, optional deploy', function (): void {
    $manifest = (new ManifestReader)->read("build: \"npm ci && npm run build\"\noutput_dir: dist/\neditable_paths:\n  - ./content/\n  - layouts\n  - content\ndeploy: ./deploy.sh\n");

    expect($manifest->build)->toBe('npm ci && npm run build')
        ->and($manifest->outputDir)->toBe('dist')
        ->and($manifest->editablePaths)->toBe(['content', 'layouts'])
        ->and($manifest->deploy)->toBe('./deploy.sh');

    expect((new ManifestReader)->read("build: hugo\noutput_dir: public\neditable_paths: [content]\n")->deploy)->toBeNull();
});

it('refuses what does not follow the contract', function (string $yaml, string $message): void {
    expect(fn () => (new ManifestReader)->read($yaml))->toThrow(ManifestException::class, $message);
})->with([
    'not yaml' => ["build: [\n", 'YAML valido'],
    'a scalar' => ["just text\n", 'chiavi build'],
    'no build' => ["output_dir: dist\neditable_paths: [content]\n", 'comando "build"'],
    'no output dir' => ["build: hugo\neditable_paths: [content]\n", 'output_dir'],
    'output dir escaping' => ["build: hugo\noutput_dir: ../out\neditable_paths: [content]\n", 'output_dir'],
    'absolute output dir' => ["build: hugo\noutput_dir: /tmp/out\neditable_paths: [content]\n", 'output_dir'],
    'no editable paths' => ["build: hugo\noutput_dir: public\neditable_paths: []\n", 'almeno un percorso'],
    'editable path that is the root' => ["build: hugo\noutput_dir: public\neditable_paths: ['.']\n", 'percorso vuoto'],
    'editable hidden path' => ["build: hugo\noutput_dir: public\neditable_paths: ['.github']\n", 'nascosti'],
    'editable manifest' => ["build: hugo\noutput_dir: public\neditable_paths: ['pipeline.yml']\n", 'file della pipeline'],
    'editable build file' => ["build: hugo\noutput_dir: public\neditable_paths: ['package.json']\n", 'file di build'],
    'the photos\' folder' => ["build: hugo\noutput_dir: public\neditable_paths: ['pipeline-uploads']\n", 'cartella degli allegati'],
    'non-string path' => ["build: hugo\noutput_dir: public\neditable_paths: [1]\n", 'deve essere un percorso'],
]);
