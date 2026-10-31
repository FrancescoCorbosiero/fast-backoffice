# fast-backoffice

A back office for a one-person Italian web studio: clients and suppliers, quotes, Italian electronic invoices sent to and received from SdI over PEC, cash flow, and the websites and domains the studio runs for its clients.

**Status: every phase of the roadmap is built, phases 0 to 8 (foundation, contacts, cash, invoicing, quotes, SdI over PEC, managed assets, leads, calendar, mail campaigns, production setup). Left for the owner: the SdI first-send checklist (sdi.md §9), building the production images on the server (docs/runbook.md), and moving the development toolchain to PHP 8.4.** See the [roadmap](docs/roadmap.md).

- [Design](docs/design.md) — scope, principles, modules, core models
- [Electronic invoicing](docs/sdi.md) — FatturaPA, SdI over PEC, stamp duty, archiving
- [Roadmap](docs/roadmap.md) — build phases and progress
- [Lessons from hive-crm](docs/lessons-from-hive-crm.md) — what the previous version got wrong, and how this one prevents it

## Running it

Requirements: PHP 8.3+ with `intl`, `pdo_pgsql`, `redis`, `gd`, `zip`; PostgreSQL; Redis. Or Docker:

```bash
docker compose up -d
docker compose exec app composer setup
docker compose exec app php artisan owner:create you@example.test
```

Then open <http://localhost:8000/admin>, sign in and set up two-factor authentication (required). Without Docker: `composer setup`, `php artisan owner:create you@example.test`, `php artisan serve`.

Checks, as CI runs them: `composer ci` (Pint, Larastan at level max, Pest on PostgreSQL).

Built with Laravel 13 and Filament 5. A ground-up redesign of [hive-crm](https://github.com/FrancescoCorbosiero/hive-crm).
