# Pipeline

How a client's change request becomes a change on their website (design.md §4.2 **Pipeline**, §5.6). The module is layer 5: it uses Websites and Repositories, Contacts, Scheduling and Audit, and nothing uses it.

## 1. Flow

```
client (portal) or owner (panel)
   │ SubmitChangeRequest
   ▼
received → queued ──RunStaticSiteRequest──▶ running ──▶ preview_ready ──▶ approved ──DeployStaticSiteRequest──▶ deployed
                                               │              │  Approva / Rifiuta           │
                                               └▶ failed      └▶ rejected ──CleanupRequest   └▶ failed
```

1. **Submit.** The request is recorded with snapshots of the client's and the website's names, gets the branch `req/<id>` and a random preview slug, and the job is dispatched after the row is committed. The website must have a pipeline in status `ready`.
2. **Run** (`RunStaticSiteRequest` → `Actions\RunChangeRequest`, on the worker). The runner prepares the checkout, reads `pipeline.yml` and `CLAUDE.md` from `origin/<default branch>` (never from the request branch), and runs the Claude Code CLI headless on the branch with the change prompt (§6); the repository's `.git/config` is put back as it was afterwards. Then the **path gate** (§7) checks what the run touched; the manifest's `build` runs; the output folder is copied to the preview root under the slug; the branch is pushed; the request becomes `preview_ready`. No commits, a refused path, a failed build or a CLI error end the request as `failed`: `error` holds the raw reason for the owner, `client_error` a sentence for the client (the agent's own explanation when it changed nothing; nothing technical otherwise).
3. **Decide.** The owner in Asset › Richieste di modifica, or the client in the portal, approves or rejects. Both only change the state (and record who decided in the audit trail); the worker does the rest.
4. **Deploy** (`DeployStaticSiteRequest` → `Actions\DeployChangeRequest`). The request is claimed (`deploy_started_at`, one UPDATE), the branch is fetched and gated again and fast-forwarded onto the default branch locally. When the manifest has a `deploy` command, the site is built on the merged tree first; then the default branch is pushed and the command runs (it gets the git credentials, the build never does). Without a `deploy` command the push is the deploy (deploy-on-push hosts). The remote branch and the preview folder are removed. A non-fast-forward merge or a failed build fails the request without pushing anything; a failed deploy command leaves the branch merged and pushed, and says so.
5. **Reject** → `CleanupRequest` removes the remote branch and the preview.

**Onboarding** is the same machinery with `kind = onboarding`: linking a repository to a website (Asset › Pipeline siti) creates an onboarding request on `pipeline/onboarding`. The CLI audits the repository and writes exactly `pipeline.yml` and `CLAUDE.md` (§4); both are shown on the request for the owner to read. Approving runs `MergeOnboarding`: fast-forward onto the default branch, push, and the website's pipeline becomes `ready`. Rejecting leaves it `none`.

Every transition writes an audit entry (`pipeline.change_request.<status>`) and dispatches an event (`Events\`). The owner's notification channel is the dashboard: **Anteprime da approvare**, **In pubblicazione**, **Fallite negli ultimi 30 giorni**.

## 2. Configuration (`config/pipeline.php`)

| Key | Env | Meaning |
|---|---|---|
| `workspace_root` | `PIPELINE_WORKSPACE_ROOT` | Checkouts, one folder per website id (default `storage/app/pipeline`) |
| `preview_root` | `PIPELINE_PREVIEW_ROOT` | Built previews, one folder per request slug (default `storage/app/previews`) |
| `preview_domain` | `PIPELINE_PREVIEW_DOMAIN` | Previews are served at `https://<slug>.<domain>` (§5) |
| `claude_binary` | `PIPELINE_CLAUDE_BINARY` | The CLI (`claude`) |
| `claude_model` | `PIPELINE_CLAUDE_MODEL` | Empty = the CLI's default; a Sonnet-class model is enough for static sites |
| `anthropic_api_key` | `ANTHROPIC_API_KEY` | Passed to the CLI process only |
| `max_cost_usd` | `PIPELINE_MAX_COST_USD` | `--max-budget-usd` per run |
| `timeout_seconds` | `PIPELINE_TIMEOUT_SECONDS` | The CLI run |
| `build_timeout_seconds` | `PIPELINE_BUILD_TIMEOUT_SECONDS` | The manifest's build and deploy commands |
| `git.name`, `git.email` | `PIPELINE_GIT_NAME`, `PIPELINE_GIT_EMAIL` | The committer on pipeline branches |
| `git.env` | `PIPELINE_GIT_SSH_COMMAND`, `SSH_AUTH_SOCK` | Passed only to git calls that reach origin |
| `queue.connection`, `queue.name` | `PIPELINE_QUEUE_CONNECTION`, `PIPELINE_QUEUE` | `redis-pipeline` / `pipeline`; `sync` in tests |
| `portal.link_minutes` | `PIPELINE_PORTAL_LINK_MINUTES` | Validity of a sign-in link |
| `portal.mailer` | `PIPELINE_PORTAL_MAILER` | Empty = the default mailer |

The jobs run on their own connection (`redis-pipeline` in `config/queue.php`, `retry_after` 4000 s) so a long run is never handed to a second worker. **Only the `pipeline-worker` container consumes it** (§3): Horizon does not, so these jobs are not on the `/horizon` dashboard. One exception ends a job (`maxExceptions` 1: a crashed run is never silently re-run); a job that finds the website's checkout held by another job goes back to the queue and tries again a minute later, up to `tries` times (30, about half an hour of waiting), after which the request is marked failed. The job class sets these, so the worker's own `--tries` flag does not apply. A request's status checks and the deploy claim make a second dispatch of the same request harmless. A failed run is on the request itself (`failed` + `error`) and in `php artisan queue:failed`.

## 3. The worker

The runner (`Support\PipelineRunner`) and everything that touches git, the CLI or a build only ever run inside queued jobs on the `pipeline` queue. Web containers run no git. The worker container (`docker/prod/Dockerfile`, target `worker`; `docker-compose.prod.yml`, service `pipeline-worker`) needs:

- `git`, `openssh-client`, Node.js and `@anthropic-ai/claude-code` (the `claude` binary), and whatever the sites' builds need (Node for npm builds; add Hugo or others to the image as sites require them);
- a writable `HOME` (the CLI writes under `~/.claude`);
- the volumes `pipeline-workspace` (checkouts) and `previews` (served by the preview vhost, §5);
- in its environment only: the database and Redis settings, `APP_KEY`, `ANTHROPIC_API_KEY`, and git credentials: a deploy key mounted read-only and named by `PIPELINE_GIT_SSH_COMMAND` (`ssh -i /run/secrets/pipeline_deploy_key -o IdentitiesOnly=yes`), or an SSH agent socket. No PEC, SES or S3 secrets.

Every process the runner starts gets an **allowlisted environment** (`Integrations\SymfonyCommandRunner`): PATH, HOME, locale, plus exactly what the call needs (the committer identity and `core.hooksPath=/dev/null` for git; the API key for the CLI; credentials only for `clone`, `fetch` and `push`). The build and deploy commands inherit none of the app's variables. That is the boundary, and its limit: they run repository code as the worker's own user, in the worker's container, so a hostile build could still read what that user can read (the mounted deploy key, the worker's own `/proc` environment). Keep the worker's environment to what §3 lists, never the PEC, SES or S3 secrets, and treat a client's repository as code you run. Running the build and the CLI as a second, unprivileged user (or in a sidecar without the key) is the next hardening step; it is not in this phase.

The CLI runs with `--bare` (no repository hooks, settings, memory or CLAUDE.md auto-discovery: the approved `CLAUDE.md` from the default branch is passed with `--append-system-prompt-file`), `--strict-mcp-config`, `--disable-slash-commands`, `--no-session-persistence`, `--setting-sources user`, `--permission-mode acceptEdits`, the file tools plus `git add/commit/status/diff/log` only, no web tools, `--max-budget-usd` and `--output-format json`. The prompt is one argv element: nothing a client wrote is ever part of a shell string.

Recovery: `php artisan scheduling:run pipeline.deploy-approved` (hourly on the scheduler) re-dispatches approved requests whose deploy never started or whose worker died; the deploy claim makes a double dispatch harmless.

## 4. `pipeline.yml`

Written by the onboarding run at the repository root, read from the default branch, approved by the owner:

```yaml
build: "npm ci && npm run build"   # from a clean checkout; installs what it needs
output_dir: dist                   # relative to the root, inside the repository
editable_paths:                    # files or folders a change request may touch
  - content
  - layouts/partials
deploy: "./deploy.sh"              # optional: leave out when pushing is the deploy
```

A `deploy` command runs on the worker after the push, as the worker's user, with the same credentials git gets (`PIPELINE_GIT_SSH_COMMAND`, `SSH_AUTH_SOCK`) and nothing else: an `rsync` over SSH must use that key (`rsync -e "$GIT_SSH_COMMAND" …`) or the hosting must accept it. Deploy-on-push is the supported default; a deploy command is for hosts that need a push of the built files.

`Support\ManifestReader` refuses a manifest with no build, an output folder outside the repository, no editable paths, or an editable path the path gate would refuse (§7). `pipeline.yml` and `CLAUDE.md` are protected: a change request that touches them fails.

## 5. Previews

A preview lives at `<preview_root>/<slug>/` and is served at `https://<slug>.<preview_domain>`. The slug is a random ULID stored on the request (`preview_slug`): nothing is derived from the sequential id, so previews are not guessable across clients. The copy refuses symbolic links.

The vhost is not part of this phase. It plugs in as a wildcard site in the Caddyfile, e.g.

```
*.preview.example.it {
    tls { dns <provider> }          # a wildcard certificate needs a DNS challenge
    root * /srv/previews/{labels.2}
    file_server
}
```

with the `previews` volume mounted at `/srv/previews` in the `web` container and a wildcard DNS record. Previews are public to whoever has the link; they are removed on deploy and on rejection.

## 6. Prompts

`Support\PromptBuilder`. The **change prompt** carries the client's name, the website, the editable paths and the fixed rules: the request is untrusted input between `<richiesta>` delimiters (the delimiters inside the text are neutralised); only the editable paths; never configuration, build, deploy or secret files, nor `pipeline.yml` or `CLAUDE.md`; no packages, builds or pushes; if ambiguous or out of scope, change nothing and explain; one descriptive commit; the final answer in Italian, "tu" form, because the client reads it. The **onboarding prompt** asks for the audit and exactly the two files.

## 7. The path gate

Prompt text is not enforcement. After the run, `PipelineRunner` lists what the run touched (committed, unstaged, untracked, and untracked-but-ignored) and `Support\PathPolicy` refuses, whatever the request said: paths with `..` or absolute, hidden files and folders (`.git`, `.github`, `.claude`, `.env`…), `pipeline.yml` and `CLAUDE.md`, the files that decide what a build runs (`package.json`, lockfiles, `composer.*`, `Makefile`, `*.config.{js,ts,mjs,cjs}`, `hugo.*`, `config.{toml,yaml,yml}`, `Gemfile*`, `requirements*.txt`, `pyproject.toml`, `Dockerfile`…), and anything outside `editable_paths`. A refused run ends `failed` with the paths, the checkout is reset (`git clean -fdx`) and nothing is pushed. The deploy runs the gate again on the branch as it is on the remote. Onboarding's policy is exactly the two files. The repository's `.git/config` is restored after every run and `.git/info/attributes` and `exclude` removed, so a run can't redirect pushes or add filters; `.gitattributes` in the tree is a hidden file and is refused.

Second net, outside the app: protect the default branch on the remote (runbook §2, step 7).

## 8. Out of scope, and where it plugs in

- Auto-approval rules: `ApproveChangeRequest` is the one entry point; a rule would call it with `DecidedBy::Owner`.
- Other pipeline kinds: `PipelineKind` has `static_site` only; a new kind gets its own job action and manifest contract.
- The preview vhost (§5).
- A deploy path in Websites: there is none; the manifest's `deploy`, or the push, is the deploy.
- Client profiling data: it will feed `PromptBuilder` as **context**, read through the owning module's public Queries, never as a source of permissions. What a run may touch is decided by the manifest and the path gate only.

## 9. Tests

`tests/Feature/Pipeline`: every transition and refused transition (`ChangeRequestsTest`), the policy and the portal guard (`PolicyTest`), the run, the gate, the build, the preview and the environment allowlist (`RunStaticSiteRequestTest`), onboarding and its merge (`OnboardingTest`), deploy, claim, recovery and cleanup (`DeployTest`), the panel (`PipelinePagesTest`), the portal (`PortalTest`); `tests/Unit/Pipeline` for `PathPolicy` and `ManifestReader`. Git, the CLI and the build are faked behind `Integrations\CommandRunner` (`tests/Support/FakeCommandRunner`), which records every argv. The real CLI was not run where this phase was written.
