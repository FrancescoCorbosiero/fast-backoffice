<?php

declare(strict_types=1);

use App\Modules\Pipeline\Data\PipelineManifest;
use App\Modules\Pipeline\Support\PathPolicy;

it('accepts only paths under the editable ones', function (): void {
    $policy = PathPolicy::forManifest(new PipelineManifest('hugo', 'public', ['content', 'layouts/partials/', 'static/favicon.ico']));

    expect($policy->refuses('content/orari.md'))->toBeNull()
        ->and($policy->refuses('./content/orari.md'))->toBeNull()
        ->and($policy->refuses('layouts/partials/footer.html'))->toBeNull()
        ->and($policy->refuses('static/favicon.ico'))->toBeNull()
        ->and($policy->refuses('content'))->toBeNull()
        ->and($policy->refuses('contents/x.md'))->toBe('fuori dai percorsi modificabili')
        ->and($policy->refuses('layouts/index.html'))->toBe('fuori dai percorsi modificabili')
        ->and($policy->refuses('static/favicon.ico.bak'))->toBe('fuori dai percorsi modificabili');
});

it('always refuses hidden files, escapes, the pipeline files and the files that drive a build', function (string $path, string $reason): void {
    $policy = new PathPolicy(['.', '/', 'content', '']);

    expect($policy->refuses($path))->toBe($reason);
})->with([
    ['.env', 'file o cartella nascosti'],
    ['.claude/settings.json', 'file o cartella nascosti'],
    ['content/.htaccess', 'file o cartella nascosti'],
    ['../x', 'percorso fuori dal repository'],
    ['content/../../x', 'percorso fuori dal repository'],
    ['/etc/passwd', 'percorso fuori dal repository'],
    ['pipeline.yml', 'file della pipeline'],
    ['CLAUDE.md', 'file della pipeline'],
    ['package.json', 'file di build o configurazione'],
    ['content/package-lock.json', 'file di build o configurazione'],
    ['hugo.toml', 'file di build o configurazione'],
    ['astro.config.mjs', 'file di build o configurazione'],
    ['requirements-dev.txt', 'file di build o configurazione'],
    ['Makefile', 'file di build o configurazione'],
    ['', 'percorso vuoto'],
    // git -z hands names over exactly: " content/x.js" is a folder called " content", not content/.
    [' content/x.js', 'nome di file non valido'],
    ['content/x.md ', 'nome di file non valido'],
    ["content/a\tb.md", 'nome di file non valido'],
    ["content/a\nb.md", 'nome di file non valido'],
]);

it('lets onboarding write exactly the two pipeline files', function (): void {
    $policy = PathPolicy::onboarding();

    expect($policy->refuses('pipeline.yml'))->toBeNull()
        ->and($policy->refuses('CLAUDE.md'))->toBeNull()
        ->and($policy->refuses('content/x.md'))->toBe('fuori dai percorsi modificabili')
        ->and($policy->refuses('.claude/CLAUDE.md'))->toBe('file o cartella nascosti')
        ->and($policy->refused(['pipeline.yml', 'README.md']))->toBe(['README.md (fuori dai percorsi modificabili)']);
});
