<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Runs bin/prod in a copy of the repository's layout, where docker and git
 * only write down how they were called. The fake docker answers with the
 * STUB_* variables in $answers; "{root}" stands for the copy's folder.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $answers
 * @return array{process: Process, calls: list<string>, root: string}
 */
function runProd(array $arguments, ?string $envFile = "APP_ENV=production\n", array $answers = []): array
{
    $root = sys_get_temp_dir().'/bin-prod-'.bin2hex(random_bytes(6));
    mkdir($root.'/bin', 0o777, true);
    mkdir($root.'/stub');
    copy(dirname(__DIR__, 2).'/bin/prod', $root.'/bin/prod');
    touch($root.'/docker-compose.prod.yml');
    touch($root.'/docker-compose.proxy.yml');
    if ($envFile !== null) {
        file_put_contents($root.'/.env.production', $envFile);
    }

    file_put_contents($root.'/stub/docker', <<<'SH'
        #!/bin/sh
        printf 'docker %s\n' "$*" >> "$STUB_LOG"
        [ -z "${COMPOSE_FILE-}" ] || printf 'COMPOSE_FILE in the environment: %s\n' "$COMPOSE_FILE" >> "$STUB_LOG"
        case "$*" in
            *"ps -aq"*) printf '%s' "${STUB_CONTAINERS-}" ;;
            *"ps -q pipeline-worker"*) printf '%s' "${STUB_WORKER-}" ;;
            *"config --hash"*) printf '%s' "${STUB_HASHES-}" ;;
            *"config --services"*) printf '%s' "${STUB_SERVICES-}" ;;
            *"project.config_files"*) printf '%s' "${STUB_CONFIG_FILES-}" ;;
            *"compose.config-hash"*) printf '%s' "${STUB_STATES-}" ;;
            "image inspect"*) printf '%s' "${STUB_IMAGE-}" ;;
            *reservedSize*) printf '%s' "${STUB_RESERVED-0}" ;;
        esac
        SH);
    file_put_contents($root.'/stub/git', "#!/bin/sh\nprintf 'git %s\\n' \"\$*\" >> \"\$STUB_LOG\"\n");
    foreach (['bin/prod', 'stub/docker', 'stub/git'] as $executable) {
        chmod($root.'/'.$executable, 0o755);
    }

    // A COMPOSE_FILE left in the shell must not choose the files.
    $environment = ['PATH' => $root.'/stub:'.getenv('PATH'), 'STUB_LOG' => $root.'/calls.log', 'COMPOSE_FILE' => 'from-the-shell.yml'];
    foreach ($answers as $name => $value) {
        $environment[$name] = str_replace('{root}', $root, $value);
    }

    $process = new Process([$root.'/bin/prod', ...$arguments], $root, $environment);
    $process->run();

    $calls = is_file($root.'/calls.log') ? file($root.'/calls.log', FILE_IGNORE_NEW_LINES) : false;
    (new Process(['rm', '-rf', $root]))->run();

    return ['process' => $process, 'calls' => $calls === false ? [] : $calls, 'root' => $root];
}

const PROD_COMPOSE = 'docker compose -f docker-compose.prod.yml --env-file .env.production';

it('runs every command with .env.production and the production compose file', function (array $arguments, string $expected): void {
    $run = runProd($arguments);

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['calls'])->toContain(PROD_COMPOSE.' '.$expected)
        ->and(implode("\n", $run['calls']))->not->toContain('COMPOSE_FILE in the environment');
    foreach ($run['calls'] as $call) {
        if (str_starts_with($call, 'docker compose')) {
            expect($call)->toStartWith(PROD_COMPOSE);
        }
    }
})->with([
    'artisan' => [['artisan', 'migrate', '--force'], 'exec app php artisan migrate --force'],
    'mail-test' => [['mail-test', 'owner@studio.test'], 'exec app php artisan mail:test owner@studio.test'],
    'up' => [['up'], 'up -d'],
    'logs' => [['logs', 'horizon'], 'logs --tail=500 horizon'],
    'shell' => [['shell'], 'exec app sh'],
    'backup' => [['backup'], 'exec -T backup backup'],
    'restore-drill' => [['restore-drill'], 'exec -T backup restore latest'],
    'compose' => [['compose', 'ps'], 'ps'],
]);

it('refuses to run without .env.production, before calling docker', function (): void {
    $run = runProd(['status'], envFile: null);

    expect($run['process']->getExitCode())->toBe(1)
        ->and($run['process']->getErrorOutput())->toContain('copy .env.production.example to .env.production')
        ->and($run['calls'])->toBe([]);
});

it('points restart at up, since a restarted container keeps its old .env.production', function (): void {
    $run = runProd(['restart']);

    expect($run['process']->getExitCode())->toBe(1)
        ->and($run['process']->getErrorOutput())->toContain('bin/prod up recreates what changed')
        ->and(implode("\n", $run['calls']))->not->toContain(' up ')->not->toContain(' restart');
});

it('lets COMPOSE_FILE in .env.production choose the compose files', function (): void {
    $run = runProd(['up'], "APP_ENV=production\nCOMPOSE_FILE=docker-compose.prod.yml:docker-compose.proxy.yml\n");

    expect($run['calls'])->toContain('docker compose --env-file .env.production up -d')
        ->and(implode("\n", $run['calls']))->not->toContain(' -f ')->not->toContain('COMPOSE_FILE in the environment');
});

it('keeps the compose files the running containers were created with, so an up keeps the proxy overlay', function (): void {
    $run = runProd(['up'], answers: [
        'STUB_CONTAINERS' => "c0ffee\n",
        'STUB_CONFIG_FILES' => '{root}/docker-compose.prod.yml,{root}/docker-compose.proxy.yml',
    ]);

    expect($run['calls'])->toContain("docker compose -f {$run['root']}/docker-compose.prod.yml -f {$run['root']}/docker-compose.proxy.yml --env-file .env.production up -d");
});

it('falls back to the production compose file when those files are gone', function (): void {
    $run = runProd(['up'], answers: [
        'STUB_CONTAINERS' => "c0ffee\n",
        'STUB_CONFIG_FILES' => '/srv/moved-away/docker-compose.prod.yml',
    ]);

    expect($run['calls'])->toContain(PROD_COMPOSE.' up -d');
});

it('tells which containers run with the current .env.production, compose files and images', function (): void {
    $run = runProd(['status'], answers: [
        'STUB_CONTAINERS' => "a1\nh1\ns1\n",
        'STUB_HASHES' => "app 111\nhorizon 222\nscheduler 444\nweb 333\n",
        'STUB_STATES' => "app running 2026-11-08T10:12:33.1Z 111 sha256:built fast-backoffice/app:latest\n"
            ."horizon running 2026-11-08T10:12:34.1Z 999 sha256:built fast-backoffice/app:latest\n"
            ."scheduler running 2026-11-08T10:12:35.1Z 444 sha256:older fast-backoffice/app:latest\n",
        'STUB_IMAGE' => 'sha256:built',
    ]);

    expect($run['process']->getOutput())
        ->toMatch('/^app +running +2026-11-08 10:12 +yes$/m')
        ->toMatch('/^horizon +running +2026-11-08 10:12 +no, created before \.env\.production or the compose files changed: bin\/prod up recreates it$/m')
        ->toMatch('/^scheduler +running +2026-11-08 10:12 +no, its image was rebuilt since: bin\/prod up recreates it$/m')
        ->toMatch('/^web +- +- +not created: bin\/prod up creates it$/m');
});

it('deploys in the order of the runbook', function (): void {
    $run = runProd(['deploy']);

    $steps = array_map(fn (string $step): int|false => array_search($step, $run['calls'], true), [
        'git pull --ff-only',
        PROD_COMPOSE.' build',
        PROD_COMPOSE.' up -d --no-deps app web',
        PROD_COMPOSE.' exec -T app php artisan migrate --force',
        PROD_COMPOSE.' up -d --no-deps horizon scheduler',
        PROD_COMPOSE.' exec -T horizon php artisan horizon:terminate',
        PROD_COMPOSE.' up -d --no-deps backup',
        PROD_COMPOSE.' up -d --no-deps pipeline-worker',
    ]);
    $sorted = $steps;
    sort($sorted);

    expect($run['process']->getExitCode())->toBe(0)
        ->and($steps)->not->toContain(false)
        ->and($steps)->toBe($sorted);
});

it('leaves a pipeline worker in the middle of a request alone', function (array $arguments, string $recreated): void {
    $run = runProd($arguments, answers: [
        'STUB_WORKER' => "w0rker\n",
        'STUB_RESERVED' => '1',
        'STUB_SERVICES' => "app\nhorizon\npipeline-worker\nweb\n",
    ]);

    expect($run['calls'])->toContain(PROD_COMPOSE.' '.$recreated)
        ->and(implode("\n", $run['calls']))->not->toMatch('/up -d.* pipeline-worker/')
        ->and($run['process']->getOutput())->toContain('bin/prod up pipeline-worker');
})->with([
    'up' => [['up'], 'up -d app horizon web'],
    'deploy' => [['deploy', '--no-pull'], 'up -d --no-deps backup'],
]);
