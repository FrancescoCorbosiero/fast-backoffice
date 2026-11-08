# Runbook

How fast-backoffice runs in production (design.md §7), and what to do when something happens. One server, Docker Compose, Caddy for TLS. Commands are run from the checkout on the server unless said otherwise.

## 1. What runs

| Service | Image / command | Notes |
|---|---|---|
| `web` | `fast-backoffice/web` (Caddy) | Ports 80/443, Let's Encrypt for `$DOMAIN`, serves `public/`, FastCGI to `fast-backoffice-fpm:9000` (the `app` container's alias: on a shared proxy network a plain `app` could be another stack's) |
| `app` | `fast-backoffice/app`, `php-fpm` | The panel, the client portal (`/area-clienti/`) and the webhooks (`/webhooks/calcom`, `/webhooks/ses`, `/unsubscribe/{token}`). Volume `pipeline_uploads`: the change requests' photos (docs/pipeline.md §10) |
| `horizon` | same image, `php artisan horizon` | Queue: campaign emails. Dashboard at `/horizon`, owner only |
| `scheduler` | same image, `php artisan schedule:work` | The Scheduling module's jobs (`/admin/jobs` lists them). Volume `pipeline_uploads`, for `pipeline.prune-attachments` |
| `pipeline-worker` | `fast-backoffice/worker` (the app image plus git, Node and the Claude Code CLI), `php artisan queue:work redis-pipeline` | The only consumer of the pipeline queue (docs/pipeline.md §3): change requests, onboarding, deploys. Volumes `pipeline-workspace`, `previews` and `pipeline_uploads` (read-only) |
| `pgsql` | `postgres:18-alpine` | Volume `pgsql` |
| `redis` | `redis:7-alpine`, AOF on | Sessions, cache, queue |
| `backup` | `docker/backup` | `pg_dump` to S3 nightly at `BACKUP_HOUR`, and the mirror of the `pipeline_uploads` volume |

Files (PDFs, XML, PEC envelopes) are in the S3-compatible bucket `FILES_S3_BUCKET`: turn **versioning** on there; that is their backup. The change requests' photos are not: the pipeline worker never holds S3 credentials, so they live on the `pipeline_uploads` volume, mirrored nightly to the backup bucket (§4).

## 2. First deploy

1. A server with Docker and Compose, DNS for `$DOMAIN` pointing at it, ports 80 and 443 open.
2. `git clone` the repository; `cp .env.production.example .env.production`; fill every value. `APP_KEY` is `base64:` followed by the output of `openssl rand -base64 32` (Compose refuses to run without it, and the image refuses to start).
3. Build and start:

```bash
bin/prod up
```

From here on every command goes through `bin/prod` (`bin/prod help` lists them). It runs Docker Compose with `.env.production` and the production compose files, the ones the containers must be created from, so nobody types `-f docker-compose.prod.yml --env-file .env.production` or forgets it; `bin/prod compose <arguments>` is any other Compose command with the same files. The images carry no configuration: `.dockerignore` keeps `.env*` and `docker/secrets` out of the build.

4. Migrate and create the owner:

```bash
bin/prod artisan migrate --force
bin/prod artisan db:seed --force
bin/prod artisan owner:create you@example.it
```

5. Sign in at `https://$DOMAIN/admin` (two-factor authentication is optional: profile page, when you want it), then fill Impostazioni: profilo aziendale, fatture, numerazione, SdI e PEC (sdi.md §9), campagne email.
6. External services: SES (verify the domain of `MAIL_FROM_ADDRESS` in the region of `AWS_DEFAULT_REGION`, or SES rejects every mail with "Email address is not verified"; request production access, since the sandbox delivers only to verified recipients; `bin/prod mail-test you@example.it` sends one email at once and prints SES's answer; create an SNS topic subscribed to `https://$DOMAIN/webhooks/ses`, put its ARN in `SES_SNS_TOPIC_ARN`); Cal.com (a webhook to `https://$DOMAIN/webhooks/calcom` with the secret in `CALCOM_WEBHOOK_SECRET`, an API key in `CALCOM_API_KEY`).
7. The pipeline worker (docs/pipeline.md §3): `ANTHROPIC_API_KEY` and `PIPELINE_*` in `.env.production`; a deploy key for the clients' repositories at `docker/secrets/pipeline_deploy_key` (owned by the container's `www-data`, uid 82: `chown 82 docker/secrets/pipeline_deploy_key && chmod 0400 …`; mounted read-only into `pipeline-worker`, named by `PIPELINE_GIT_SSH_COMMAND`; the worker's user must be able to read it, and the build commands run as that same user: docs/pipeline.md §3); the `worker` image built with the others. On every client repository, **protect the default branch on the remote**: no force-push, no deletion, and, where the provider allows it, let the pipeline's credential push only `req/*` and `pipeline/*` (GitHub rulesets or GitLab protected branches with a key restricted to those patterns). The runner never pushes the default branch except by fast-forward from the deploy actions; this is the second net behind that rule. Previews need the wildcard vhost of docs/pipeline.md §5 (not part of the images yet).
8. `bin/prod status` shows every container up to date and the mailer and sender the app uses. Check `https://$DOMAIN/up` answers 200, `/admin` asks for a login and `/area-clienti/` shows the sign-in form.

### 2b. Behind an existing caddy-docker-proxy

When the VPS already runs [caddy-docker-proxy](https://github.com/lucaslorentz/caddy-docker-proxy) on ports 80/443 with the `caddy` network, `web` must not bind those ports: name the overlay in `.env.production`, and `bin/prod` (and Compose itself) adds it to every command.

```bash
COMPOSE_FILE=docker-compose.prod.yml:docker-compose.proxy.yml
```

Without that line, `bin/prod` uses the files the running containers were created with (Compose records them on each container), so a server started with the overlay keeps it; a server with nothing running yet needs the line before its first `bin/prod up`.

The overlay (`docker-compose.proxy.yml`) removes `web`'s ports, puts it on the `caddy` network, mounts `docker/prod/Caddyfile.proxy` (plain HTTP on :80, `auto_https off`, the proxy's addresses trusted for `X-Forwarded-*`) and sets the labels `caddy: $DOMAIN` and `caddy.reverse_proxy: {{upstreams 80}}`; the proxy gets the certificate. `ACME_EMAIL` is unused in this mode. The preview vhost (docs/pipeline.md §5) will plug in the same way: a second host label on `web` for `*.$PIPELINE_PREVIEW_DOMAIN`, with the `previews` volume mounted and a wildcard certificate configured on the proxy.

## 3. Deploy a new version

```bash
bin/prod deploy
```

It pulls (`git pull --ff-only`) and runs the rest from the version it pulled: builds the images, recreates `app` and `web`, migrates, recreates `horizon` and `scheduler`, restarts Horizon on the new code (`horizon:terminate`), recreates `backup`, then `pipeline-worker` unless the worker is in the middle of a job (a run cut short ends failed, and "Ripeti richiesta" starts it again): it then says so, and `bin/prod up pipeline-worker` finishes the deploy once Asset › Richieste di modifica shows none "In lavorazione". It ends with `bin/prod status`.

Migrations run after the new `app` is up and before the workers restart: the entrypoint only caches configuration, so a container restart never changes the schema by surprise.

The release that brought photos to change requests creates the `pipeline_uploads` volume: `app`, `scheduler`, `pipeline-worker` and `backup` all mount it, so all four are recreated by the deploy. The images create its mount point owned by `www-data` (uid 82), and a new volume takes that owner.

Rollback: `git checkout <previous tag>`, then `bin/prod deploy --no-pull`. Migrations in this project add tables and columns; they are not rolled back in production, the old code ignores what it doesn't know.

## 4. Backups and the restore drill

The `backup` service dumps the database every night (`pg_dump`, custom format, compressed) to `s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/`, checks the dump is readable, and prunes copies older than `BACKUP_KEEP_DAYS`. Run one now:

```bash
bin/prod backup
```

The same run mirrors the change requests' photos (the `pipeline_uploads` volume) to `s3://$BACKUP_S3_BUCKET/$BACKUP_S3_UPLOADS_PREFIX/` with `aws s3 sync --delete`: a photo the retention job deleted (docs/pipeline.md §10) goes from the mirror too. Turn **versioning** on for the backup bucket, with a lifecycle rule that expires noncurrent versions after `BACKUP_KEEP_DAYS`: a dump restored from a few days back still finds its photos among the earlier versions, and nothing outlives the retention by more than that.

**Restore drill, once a month.** Nothing touches production:

```bash
bin/prod restore-drill
```

It restores the latest dump into a scratch database next to the live one and counts contacts and invoices: expect `restored <name> into fast_backoffice_restore_test: N contacts, M invoices`, with numbers that match the panel; the scratch database is dropped right after. Then it restores the photos' mirror into a scratch folder in the `backup` container, checks five photos stored before last night against their checksums in the database, and removes the folder: expect `restored N files into /tmp/pipeline-uploads-restore-test: 5 of 5 sampled checksums match` (fewer than five when fewer photos are kept).

**Restoring production for real** (after a disaster, on a fresh server with the same `.env.production`):

```bash
bin/prod compose up -d pgsql redis backup
bin/prod compose exec backup restore latest fast_backoffice
bin/prod compose exec backup restore-uploads /srv/pipeline-uploads
bin/prod up
```

Files come back from the bucket as they are (versioning covers deletions). The photos come back from their mirror into the volume, owned by `www-data`; any the retention job had already removed are deleted again by its next run. Legal archiving of invoices is separate and not the app's job: sdi.md §8.

## 5. Day to day

- **Status**: `bin/prod status` lists every container and whether it runs with the current `.env.production`, compose files and images, then the mailer and sender the app uses and the failed jobs.
- **Logs**: `bin/prod logs` follows app, horizon, scheduler and web; `bin/prod logs pipeline-worker` one service. Piped, it prints the last 500 lines instead of following: `bin/prod logs horizon | grep -i error`. The app logs to stderr (`LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`).
- **Jobs**: Sistema › Attività programmate shows every scheduled job, its last runs and lets you run one now. Horizon at `/horizon` shows the queue, except the pipeline queue, which the `pipeline-worker` consumes outside Horizon: `bin/prod logs pipeline-worker`, the request's own log and error in Asset › Richieste di modifica, and `bin/prod artisan queue:failed`. An approved request that never deployed: `bin/prod artisan scheduling:run pipeline.deploy-approved`.
- **Mail**: `bin/prod mail-test you@example.it` sends one email at once and prints the provider's answer. Portal sign-in links and campaigns go through the queue, so a refused one is a failed job: `bin/prod artisan queue:failed`.
- **PEC**: Sistema › Casella PEC lists every message read; what needs a human is flagged and counted on the dashboard.
- **Photos**: `pipeline.prune-attachments` deletes, every night at 03:40, the photos of requests over for more than 90 days (the rows stay, marked removed). Run it now: `bin/prod artisan scheduling:run pipeline.prune-attachments`.
- **Audit**: Sistema › Audit, every fiscal and settings change.
- **Maintenance mode**: `bin/prod artisan down --secret=<word>`, and `bin/prod artisan up` to end it.
- **Health**: `GET /up` (used by uptime monitors).

## 6. Secrets

Only in `.env.production` on the server, never in the repository or the database: `APP_KEY`, database password, S3 keys, PEC password, AWS keys, Cal.com secret, `ANTHROPIC_API_KEY`; the pipeline's deploy key as a file under `docker/secrets/` (in `.gitignore`). The worker gets only what it needs (`docker-compose.prod.yml`), and hands its children an allowlisted environment (docs/pipeline.md §3). `.env.production` is in `.gitignore`. The images carry none of them (`.dockerignore`). Rotating a key: change it in `.env.production`, then `bin/prod up`: it recreates exactly the containers that use what changed, and the entrypoint re-caches configuration on start. A container reads `.env.production` only when it is created, so `docker compose restart` keeps the old values; `bin/prod status` shows anything still running with an older file.

## 7. Toolchain

The images run PHP 8.4; `composer.lock` is resolved for PHP 8.3 (`config.platform.php`) so the same lock installs on both. Moving the lock to 8.4 and Pest to 5 is a one-line change in `composer.json` once every development machine is on 8.4 (roadmap, phase 8).
