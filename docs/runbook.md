# Runbook

How fast-backoffice runs in production (design.md §7), and what to do when something happens. One server, Docker Compose, Caddy for TLS. Commands are run from the checkout on the server unless said otherwise.

## 1. What runs

| Service | Image / command | Notes |
|---|---|---|
| `web` | `fast-backoffice/web` (Caddy) | Ports 80/443, Let's Encrypt for `$DOMAIN`, serves `public/`, FastCGI to `fast-backoffice-fpm:9000` (the `app` container's alias: on a shared proxy network a plain `app` could be another stack's) |
| `app` | `fast-backoffice/app`, `php-fpm` | The panel, the client portal (`/area-clienti/`) and the webhooks (`/webhooks/calcom`, `/webhooks/ses`, `/unsubscribe/{token}`) |
| `horizon` | same image, `php artisan horizon` | Queue: campaign emails. Dashboard at `/horizon`, owner only |
| `scheduler` | same image, `php artisan schedule:work` | The Scheduling module's jobs (`/admin/jobs` lists them) |
| `pipeline-worker` | `fast-backoffice/worker` (the app image plus git, Node and the Claude Code CLI), `php artisan queue:work redis-pipeline` | The only consumer of the pipeline queue (docs/pipeline.md §3): change requests, onboarding, deploys. Volumes `pipeline-workspace` and `previews` |
| `pgsql` | `postgres:18-alpine` | Volume `pgsql` |
| `redis` | `redis:7-alpine`, AOF on | Sessions, cache, queue |
| `backup` | `docker/backup` | `pg_dump` to S3 nightly at `BACKUP_HOUR` |

Files (PDFs, XML, PEC envelopes) are in the S3-compatible bucket `FILES_S3_BUCKET`: turn **versioning** on there; that is their backup.

## 2. First deploy

1. A server with Docker and Compose, DNS for `$DOMAIN` pointing at it, ports 80 and 443 open.
2. `git clone` the repository; `cp .env.production.example .env.production`; fill every value. `APP_KEY`: `docker run --rm fast-backoffice/app php artisan key:generate --show` (after the first build), or any `base64:` 32-byte key.
3. Build and start:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

4. Migrate and create the owner:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec app php artisan owner:create you@example.it
```

5. Sign in at `https://$DOMAIN/admin` (two-factor authentication is optional: profile page, when you want it), then fill Impostazioni: profilo aziendale, fatture, numerazione, SdI e PEC (sdi.md §9), campagne email.
6. External services: SES (verify the sender, create an SNS topic subscribed to `https://$DOMAIN/webhooks/ses`, put its ARN in `SES_SNS_TOPIC_ARN`); Cal.com (a webhook to `https://$DOMAIN/webhooks/calcom` with the secret in `CALCOM_WEBHOOK_SECRET`, an API key in `CALCOM_API_KEY`).
7. The pipeline worker (docs/pipeline.md §3): `ANTHROPIC_API_KEY` and `PIPELINE_*` in `.env.production`; a deploy key for the clients' repositories at `docker/secrets/pipeline_deploy_key` (owned by the container's `www-data`, uid 82: `chown 82 docker/secrets/pipeline_deploy_key && chmod 0400 …`; mounted read-only into `pipeline-worker`, named by `PIPELINE_GIT_SSH_COMMAND`; the worker's user must be able to read it, and the build commands run as that same user: docs/pipeline.md §3); the `worker` image built with the others. On every client repository, **protect the default branch on the remote**: no force-push, no deletion, and, where the provider allows it, let the pipeline's credential push only `req/*` and `pipeline/*` (GitHub rulesets or GitLab protected branches with a key restricted to those patterns). The runner never pushes the default branch except by fast-forward from the deploy actions; this is the second net behind that rule. Previews need the wildcard vhost of docs/pipeline.md §5 (not part of the images yet).
8. Check `https://$DOMAIN/up` answers 200, `/admin` asks for a login and `/area-clienti/` shows the sign-in form.

### 2b. Behind an existing caddy-docker-proxy

When the VPS already runs [caddy-docker-proxy](https://github.com/lucaslorentz/caddy-docker-proxy) on ports 80/443 with the `caddy` network, `web` must not bind those ports: add the overlay to every compose command.

```bash
docker compose -f docker-compose.prod.yml -f docker-compose.proxy.yml --env-file .env.production up -d --build
```

The overlay (`docker-compose.proxy.yml`) removes `web`'s ports, puts it on the `caddy` network, mounts `docker/prod/Caddyfile.proxy` (plain HTTP on :80, `auto_https off`, the proxy's addresses trusted for `X-Forwarded-*`) and sets the labels `caddy: $DOMAIN` and `caddy.reverse_proxy: {{upstreams 80}}`; the proxy gets the certificate. `ACME_EMAIL` is unused in this mode. The preview vhost (docs/pipeline.md §5) will plug in the same way: a second host label on `web` for `*.$PIPELINE_PREVIEW_DOMAIN`, with the `previews` volume mounted and a wildcard certificate configured on the proxy.

## 3. Deploy a new version

```bash
git pull
docker compose -f docker-compose.prod.yml --env-file .env.production build
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --no-deps app web
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --no-deps horizon scheduler
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:terminate
```

Migrations run by hand, after the new `app` is up and before the workers restart: the entrypoint only caches configuration, so a container restart never changes the schema by surprise. `horizon:terminate` makes Horizon finish its current jobs and restart on the new code.

Rollback: `git checkout <previous tag>` and the same steps. Migrations in this project add tables and columns; they are not rolled back in production, the old code ignores what it doesn't know.

## 4. Backups and the restore drill

The `backup` service dumps the database every night (`pg_dump`, custom format, compressed) to `s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/`, checks the dump is readable, and prunes copies older than `BACKUP_KEEP_DAYS`. Run one now:

```bash
docker compose -f docker-compose.prod.yml exec backup backup
```

**Restore drill, once a month.** It restores the latest dump into a scratch database next to the live one and counts contacts and invoices; nothing touches production:

```bash
docker compose -f docker-compose.prod.yml exec backup restore latest
```

Expect `restored <name> into fast_backoffice_restore_test: N contacts, M invoices`, with numbers that match the panel. Then drop the scratch database:

```bash
docker compose -f docker-compose.prod.yml exec pgsql psql -U "$DB_USERNAME" -d postgres -c 'DROP DATABASE fast_backoffice_restore_test;'
```

**Restoring production for real** (after a disaster, on a fresh server with the same `.env.production`):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d pgsql redis backup
docker compose -f docker-compose.prod.yml exec backup restore latest fast_backoffice
docker compose -f docker-compose.prod.yml --env-file .env.production up -d
```

Files come back from the bucket as they are (versioning covers deletions). Legal archiving of invoices is separate and not the app's job: sdi.md §8.

## 5. Day to day

- **Logs**: `docker compose -f docker-compose.prod.yml logs -f app horizon scheduler web`. The app logs to stderr (`LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`).
- **Jobs**: Sistema › Attività programmate shows every scheduled job, its last runs and lets you run one now. Horizon at `/horizon` shows the queue, except the pipeline queue, which the `pipeline-worker` consumes outside Horizon: `docker compose -f docker-compose.prod.yml logs -f pipeline-worker`, the request's own log and error in Asset › Richieste di modifica, and `exec app php artisan queue:failed`. An approved request that never deployed: `exec app php artisan scheduling:run pipeline.deploy-approved`.
- **PEC**: Sistema › Casella PEC lists every message read; what needs a human is flagged and counted on the dashboard.
- **Audit**: Sistema › Audit, every fiscal and settings change.
- **Maintenance mode**: `exec app php artisan down --secret=<word>` and `up`.
- **Health**: `GET /up` (used by uptime monitors).

## 6. Secrets

Only in `.env.production` on the server, never in the repository or the database: `APP_KEY`, database password, S3 keys, PEC password, AWS keys, Cal.com secret, `ANTHROPIC_API_KEY`; the pipeline's deploy key as a file under `docker/secrets/` (in `.gitignore`). The worker gets only what it needs (`docker-compose.prod.yml`), and hands its children an allowlisted environment (docs/pipeline.md §3). `.env.production` is in `.gitignore`. Rotating a key: change it in `.env.production`, `up -d` the services that use it (the entrypoint re-caches configuration on start).

## 7. Toolchain

The images run PHP 8.4; `composer.lock` is resolved for PHP 8.3 (`config.platform.php`) so the same lock installs on both. Moving the lock to 8.4 and Pest to 5 is a one-line change in `composer.json` once every development machine is on 8.4 (roadmap, phase 8).
