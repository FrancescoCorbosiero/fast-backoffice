<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pipeline (design.md §4.2, docs/pipeline.md)
|--------------------------------------------------------------------------
|
| Client change requests run by the Claude Code CLI on a site's repository,
| previewed, approved and deployed by queued jobs on a dedicated worker.
| The CLI's API key and the git credentials live only in that worker's
| environment; this file is the only place that reads env().
|
*/

return [

    // Checkouts, one folder per website id.
    'workspace_root' => env('PIPELINE_WORKSPACE_ROOT', storage_path('app/pipeline')),

    // Built previews, one folder per request slug, served by the preview
    // vhost at https://<slug>.<preview_domain> (docs/pipeline.md §5).
    'preview_root' => env('PIPELINE_PREVIEW_ROOT', storage_path('app/previews')),
    'preview_domain' => env('PIPELINE_PREVIEW_DOMAIN', 'preview.example.test'),

    // The Claude Code CLI: binary, model (empty = the CLI's default), the
    // spend cap per run and the time the run may take.
    'claude_binary' => env('PIPELINE_CLAUDE_BINARY', 'claude'),
    'claude_model' => env('PIPELINE_CLAUDE_MODEL'),
    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
    'max_cost_usd' => (float) env('PIPELINE_MAX_COST_USD', 5),
    'timeout_seconds' => (int) env('PIPELINE_TIMEOUT_SECONDS', 1800),
    'build_timeout_seconds' => (int) env('PIPELINE_BUILD_TIMEOUT_SECONDS', 900),

    'git' => [
        // The committer identity on the branches the pipeline pushes.
        'name' => env('PIPELINE_GIT_NAME', 'fast-backoffice pipeline'),
        'email' => env('PIPELINE_GIT_EMAIL', 'pipeline@example.test'),
        // What git needs to reach the remotes, passed only to git calls that
        // talk to origin: an SSH command naming the key, or an agent socket.
        'env' => array_filter([
            'GIT_SSH_COMMAND' => env('PIPELINE_GIT_SSH_COMMAND'),
            'SSH_AUTH_SOCK' => env('SSH_AUTH_SOCK'),
        ], fn (mixed $value): bool => is_string($value) && $value !== ''),
    ],

    // The queue the jobs go to: its own connection (config/queue.php,
    // "redis-pipeline", a long retry_after) consumed only by the worker
    // that has git, node and the CLI. "sync" in tests.
    'queue' => [
        'connection' => env('PIPELINE_QUEUE_CONNECTION', 'redis-pipeline'),
        'name' => env('PIPELINE_QUEUE', 'pipeline'),
    ],

    'portal' => [
        // How long a sign-in link stays valid.
        'link_minutes' => (int) env('PIPELINE_PORTAL_LINK_MINUTES', 15),
        // The mailer for sign-in links; empty = the default mailer.
        'mailer' => env('PIPELINE_PORTAL_MAILER'),
    ],

];
