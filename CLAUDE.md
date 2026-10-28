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

To be filled in during phase 0 (set-up, tests, static analysis, formatting).

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
