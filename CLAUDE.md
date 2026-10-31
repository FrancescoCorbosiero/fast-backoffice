# CLAUDE.md

fast-backoffice is a single-user back office for an Italian web studio: clients and suppliers, quotes, FatturaPA electronic invoices sent through SdI over PEC, cash, and the websites and domains the studio runs for clients. It is a ground-up redesign of hive-crm.

## Read before working

1. `docs/roadmap.md` — the current phase and what's left. Work on the first phase that isn't done; tick boxes as you finish them.
2. `docs/design.md` — principles (§2), modules and their rules (§4), core models (§5). Every change must fit them. If a rule genuinely fights a feature, change the design doc in the same change, with the reason.
3. `docs/sdi.md` — before touching anything that creates, sends or receives a fiscal document.
4. `docs/lessons-from-hive-crm.md` — the bug classes this project exists to avoid, and the hive-crm tests to port as specifications.

## Non-negotiables

- Money is `Money` (integer cents); VAT goes only through the Kernel's engine; no floats on money paths.
- Dates are `CarbonImmutable`; month arithmetic never overflows; compare dates rather than diffing them.
- A fiscal document gets its number when it's issued, and an issued document never changes.
- Modules use each other only through their public folders (`Actions`, `Queries`, `Data`, `Events`, `Enums`, `Contracts`, `Filament/Components`), and only downward through the layers.
- `env()` only in `config/`.
- Every change ships with tests; a bug fix starts with a failing test. Tests run on PostgreSQL.
- CI stays green: Pint, Larastan at max, Pest (Unit, Feature, Arch).
- No credentials, client data or real fiscal documents in the repository: it's public. Fixtures are anonymised.

## Commands

| What | Command |
|---|---|
| Set up (install, `.env`, key, migrate) | `composer setup` |
| Everything CI runs | `composer ci` |
| Code style | `composer lint` (check) · `composer lint:fix` |
| Static analysis, Larastan level max | `composer analyse` |
| Tests, all suites | `composer test` · `vendor/bin/pest --testsuite=Unit\|Feature\|Arch` |
| One test file | `vendor/bin/pest tests/Feature/Settings/SettingsTest.php` |
| Mutation testing on the Kernel | `composer test:mutate` (needs `pcov` or Xdebug; not part of CI) |
| Create the owner | `php artisan owner:create you@example.test` |
| Run a scheduled job now | `php artisan scheduling:run scheduling.prune-runs` |
| Regenerate the FatturaPA golden files | `UPDATE_FATTURAPA_GOLDEN=1 vendor/bin/pest tests/Feature/Invoicing/TotalsAndXmlTest.php` then review the diff |
| Rebuild cached paid amounts | `php artisan invoicing:rebuild-paid` |
| Start scheduled campaigns / reconcile campaigns / sync Cal.com now | `php artisan scheduling:run mail.start-scheduled` · `mail.reconcile` · `calendar.sync` |
| Check every monitored website now | `php artisan scheduling:run websites.uptime-check` |
| Read the PEC mailbox now / send issued invoices now | `php artisan scheduling:run invoicing.pec-inbox` · `php artisan scheduling:run invoicing.sdi-send` |
| PEC round-trip test against a local mail server | `PEC_TEST_HOST=127.0.0.1 vendor/bin/pest tests/Feature/Invoicing/PecMailboxIntegrationTest.php` (GreenMail on 3025/3143; CI does this) |
| Serve locally | `php artisan serve` (then `/admin`) |
| Docker development environment | `docker compose up -d`, then the commands above through `docker compose exec app …` |

Tests run on PostgreSQL: `phpunit.xml` points at `fast_backoffice_test` on `127.0.0.1` with user and password `fast` (CI overrides these with its service container; `docker/pgsql/create-test-database.sql` creates it in Docker). The Docker images run PHP 8.4; `composer.lock` is resolved for PHP 8.3 so it installs on both.

Where things go: `app/Kernel` (money, VAT, numbering, dates, `Reference`), `app/Modules/{Module}` (see design.md §4.3), `app/Support` (shared infrastructure: the module provider base class, `Shape` for typed reads from arrays), `app/Providers/Filament/AdminPanelProvider.php` (the panel; modules contribute through plugins tagged by their providers). A new module: folder under `app/Modules`, provider extending `App\Support\ModuleServiceProvider`, entry in `config/modules.php` and in `bootstrap/providers.php` in layer order; `tests/Arch` checks all of it.

## Reference: hive-crm

<https://github.com/FrancescoCorbosiero/hive-crm>, branch `claude/blissful-euler-g03091` for the audited version. Read it for behaviour and edge cases; the lessons doc says which tests encode them. Don't copy its code or patterns without checking them against the design doc: many of them are why this project exists.

## Glossary

| Term | Meaning |
|---|---|
| Fattura / nota di credito | Invoice / credit note (TD01 / TD04) |
| FatturaPA | The Italian e-invoice XML format (FPR12 for businesses and consumers) |
| SdI | Sistema di Interscambio: the Agenzia delle Entrate's system every e-invoice passes through |
| PEC | Posta Elettronica Certificata (certified email), one of SdI's channels |
| Cedente / cessionario | Seller / customer, as the XML calls them |
| Natura | The code saying why a line carries no VAT (e.g. N2.2 for the flat-rate regime) |
| Regime forfettario (RF19) | Flat-rate tax regime: invoices carry no VAT |
| Imposta di bollo | €2 stamp duty on VAT-free invoices over €77.47 |
| Codice destinatario | The customer's 7-character SdI routing code |
| Partita IVA / codice fiscale | VAT number / tax code |
| Conservazione | Legal archiving of fiscal documents |
