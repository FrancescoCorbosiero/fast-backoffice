# fast-backoffice — Design

> Status: in development. Progress lives in [roadmap.md](roadmap.md).

## 1. What it is

A back office for a one-person Italian web studio, used only by its owner:

- **clients and suppliers**, with their fiscal identity;
- **quotes** and **invoices**: Italian electronic invoices (FatturaPA) sent to and received from SdI over PEC — see [sdi.md](sdi.md);
- **cash**: every euro in and out, expenses, recurring costs, and the cash-flow outlook;
- **the assets the studio runs for clients**: websites, domain names, code repositories, and their renewals;
- **growth**: leads, bookings (Cal.com) and email campaigns.

It is a ground-up redesign of [hive-crm](https://github.com/FrancescoCorbosiero/hive-crm). That codebase is the reference: its behaviour defines what this one must do, and its bugs shaped the rules below ([lessons-from-hive-crm.md](lessons-from-hive-crm.md)). Its code is not copied.

### Not in scope for v1

- Other users, roles or tenants. One owner, one login.
- Invoices to the public administration (FPA12: mandatory signature, split payment, CIG/CUP). The model leaves room for them.
- Acting as an SdI intermediary for anyone else.
- Bookkeeping and tax returns. The app produces clean data for the accountant and files nothing.
- Migrating hive-crm's data: hive-crm is not in production. An optional importer for reference data comes last (roadmap phase 8).

## 2. Principles

Each one exists because hive-crm broke it somewhere.

1. **One fact, one place.** Every fact is stored once; everything else is derived. An invoice's totals come from its lines. A payment is one cash movement — not a payment row plus a ledger copy kept in sync by listeners. A derived value may be cached on the row that owns it only when the same use case writes both in one transaction, and a rebuild command and a consistency test exist.
2. **Issued means immutable.** Fiscal documents follow their legal lifecycle. A number is assigned when a document is issued, never earlier, and never reused. Issued documents are corrected by new documents (credit notes), never edited or deleted.
3. **Workflows are explicit.** Anything that spans modules is one use-case class, running in one transaction, called by the UI, a command or a job. Events announce facts to whoever cares; they never carry instructions for another module.
4. **Exact arithmetic.** Money is integer cents in a `Money` value object; quantities and rates are scaled integers. VAT is computed once per rate on the summed taxable amount and rounded half away from zero — the way SdI checks `DatiRiepilogo`. No floats on any money path. Dates are `CarbonImmutable`, and month arithmetic never overflows (Jan 31 + 1 month = Feb 28).
5. **Boundaries are tests.** The module rules (§4.3) are Pest architecture tests from the first commit.
6. **Test what runs in production.** Tests run on PostgreSQL, the production major version. Larastan runs at level max in CI. Every bug fix starts with a failing test.
7. **Configuration, not environment.** `env()` appears only in `config/`.
8. **Finished slices.** A roadmap phase ends deployable, with its acceptance tests green. The next one starts after.

## 3. Stack

| Concern | Choice |
|---|---|
| Runtime | PHP 8.3 or newer; the Docker images and CI run 8.4 |
| Framework | Laravel 13 |
| Admin UI | Filament 5, on Livewire 4 |
| Database | PostgreSQL 18, in tests too |
| Cache and queues | Redis, with Horizon for workers |
| Tests | Pest 4: Unit, Feature and Arch suites (Pest 5 needs PHP 8.4 everywhere, including the development machine; it is a phase 8 item) |
| Static analysis | Larastan 3 at level max |
| Code style | Laravel Pint |
| PDF | Decided in phase 3: dompdf (as in hive-crm) or an HTML-to-PDF service for better typography |
| Hosting | Docker (app, worker, scheduler, PostgreSQL, Redis) behind Caddy, as in hive-crm |

These are the current stable majors (October 2026); `composer.lock` pins exact versions, resolved for PHP 8.3 (`config.platform.php`) so the same lock installs on 8.3 and 8.4.

## 4. Modules

### 4.1 Layers

```
4  Growth           Leads · Calendar · Mail
3  Managed assets   Websites · Domains · Repositories
2  Billing core     Catalog · Cash · Invoicing · Quotes
1  Parties          Contacts
0  Platform         Kernel (code only) · Files · Audit · Settings · Scheduling
```

A module may depend on modules in lower layers, and on modules listed before it in its own layer. Never upward, never in a cycle. When a lower module needs something from a higher one — a contact's page listing that contact's websites, the cash-flow outlook including domain renewals — the higher module **contributes** it (§4.4).

The order inside layer 2 follows the money: a payment is a cash movement, so Invoicing builds on Cash; accepting a quote creates an invoice draft, so Quotes builds on Invoicing.

### 4.2 Catalogue

**Kernel** (layer 0, code only). `Money`, quantities and VAT rates as integers; the VAT engine (line nets, per-rate summary, totals); gapless numbering sequences (§5.2); date helpers (no-overflow months, anchor days); fiscal identifiers (Partita IVA check digit, codice fiscale checksum, VAT number formats by country), used by Settings for the owner and by Contacts for everyone else; the opaque `Reference`.

**Files** (0). Stored files on S3-compatible storage, attached to any record by an opaque owner reference; generated PDFs and XML with checksums.

**Audit** (0). The audit trail: who did what to which record, written by use cases inside their transaction (`RecordAuditEntry`), read on a screen. Issuing, sending and voiding fiscal documents and every settings change go through it. A module rather than part of the Kernel because it has a table; in layer 0 before Settings because Settings writes to it.

**Settings** (0). The owner's fiscal identity (the *cedente*), invoice defaults (payment terms, legal wording, stamp-duty and INPS options), numbering series and opening numbers, and the non-secret SdI channel settings.

**Scheduling** (0). Recurring jobs are defined in code by each module; the database stores only overrides (enabled, schedule) and run history (start, end, real exit code, output). A screen lists them and can run one now.

**Contacts** (1). People and companies; roles (client, supplier, partner, other; a contact may hold several); validated fiscal identity (through the Kernel), one live contact per VAT number and per tax code; SdI addressing (`SdiAddress`: codice destinatario, PEC, portal, abroad); email consent and suppression (bounce, complaint, unsubscribe, the owner), with `isMailable()` requiring an address, consent and no suppression; merging duplicates (blanks filled, roles joined, the safer email state kept, the duplicate hidden but kept, `ContactsMerged` dispatched inside the transaction). Contacts are never deleted. Publishes the `ContactPicker` form component and the `ContactPanel` extension point for the contact page.

**Catalog** (2). Sellable services with default price, VAT treatment (rate or natura) and cadence. A template source only: lines are copied into documents, so the catalog never changes an issued document.

**Cash** (2). Accounts (bank, card, cash, PayPal…), movements in and out, categories, recurring expenses, analytics, and the cash-flow projection (consumer of the `CashFlowSchedule` extension point). Later: bank-statement import and reconciliation.

**Invoicing** (2). Sales invoices and credit notes with the lifecycle of §5.3; payment requests (pro forma); recurring invoices; supplier invoices received from SdI; FatturaPA XML; the SdI channel over PEC; courtesy PDFs; stamp duty; payments.

**Quotes** (2). Quotations with their own non-fiscal numbering, validity, statuses (draft, sent, accepted, declined, expired) and PDF. Accepting one creates an invoice draft or a payment request through Invoicing.

**Websites** (3). Sites run for clients: URL, owner, hosting renewals, subscription (billed by a recurring invoice created through Invoicing), uptime checks run concurrently.

**Domains** (3). Registrations: registrar, expiry, auto-renew, renewal cost, link to a website. "Log renewal" records the cash movement and rolls the expiry forward, once per period.

**Repositories** (3). Code repositories linked to a website and a client.

**Leads** (4). The sales pipeline. Converting a lead creates the contact, and optionally a quote and a website, in one transaction.

**Calendar** (4). Cal.com bookings from signed webhooks plus a periodic sync, linked to contacts by email.

**Mail** (4). Campaigns through Amazon SES: audience from Contacts (consent respected), per-recipient sends with rate limiting and retries, SES events through signed SNS messages (deduplicated), a bounce policy (permanent bounce: suppress; temporary: retry, suppress after repeated failures), and RFC 8058 one-click unsubscribe.

### 4.3 Inside a module

```
app/Kernel/                      Money, VAT engine, numbering, dates

app/Modules/{Module}/
    Actions/                     use cases, one public method each, transactional   ┐
    Queries/                     read API returning Data objects                    │
    Data/                        final readonly DTOs                                │ public
    Events/                      facts, carrying ids and scalars                    │
    Enums/                       value types                                        │
    Contracts/                   extension points this module consumes              │
    Filament/Components/         UI pieces other modules may embed                  ┘
    Models/                      Eloquent models
    Filament/                    resources, pages, widgets, and the module's panel plugin
    Integrations/                adapters to external systems (PEC, SES, Cal.com)
    Jobs/                        recurring jobs (Scheduling's `ScheduledJob` contract)
    Console/                     artisan commands
    Database/                    migrations, factories, seeders
    {Module}ServiceProvider.php  migrations, bindings, the panel plugin and job tags
```

Architecture tests enforce:

- outside a module, only its public folders are used — models never cross modules, not in services and not in Filament;
- dependencies follow the layers of §4.1;
- `Kernel` depends on no module;
- events carry no models; DTOs are final and readonly;
- no `env()` outside `config/`, no `dd()`/`dump()`, no `translatedFormat()` (it reads PHP date tokens, not ISO ones);
- no Carbon month arithmetic outside the Kernel (`Months`, `Recurrence`), so a date never overflows or drifts;
- every module is listed in `config/modules.php` with its layer, and its provider registered in layer order.

The layer table lives in `config/modules.php`; `tests/Arch` reads it. A module's provider extends `App\Support\ModuleServiceProvider`: it loads the module's migrations and tags its Filament plugin, which the panel provider collects.

### 4.4 Composition across modules

- **Use cases call down.** `ConvertLead` (Leads) calls `CreateContact` (Contacts), `CreateQuote` (Quotes) and `CreateWebsite` (Websites) inside one transaction.
- **Contributions flow up.** A lower module defines an extension point (an interface in its `Contracts/`, collected by container tag); higher modules register implementations in their service providers:
  - `Cash` → `CashFlowSchedule`: expected money movements — receivables, recurring invoices, recurring expenses, domain and hosting renewals;
  - `Contacts` → `ContactPanel`: sections of a contact's page — their invoices, quotes, websites, domains, bookings, campaigns;
  - `Scheduling` → job definitions;
  - the dashboard → widgets, contributed through each module's panel plugin.
- **Events notify.** A listener may subscribe only to events of modules it may depend on. Example: `Contacts\Events\ContactsMerged` lets every module that references contacts repoint its references.
- **Screens read through Queries.** A screen that needs another module's data asks that module's `Queries` (built for batches), never its tables.

## 5. Core models

### 5.1 Money and VAT (Kernel)

- `Money(int $cents, string $currency = 'EUR')`; arithmetic only between equal currencies. Single currency (EUR) by configuration.
- Quantities are integers scaled by 10 000 (four decimals); VAT rates are integer basis points (22% = 2200).
- A line's net is `round(quantity × unit price)`. The summary groups lines by (rate, natura); each group's VAT is computed on the group's summed taxable amount and rounded half away from zero; the total is taxable plus VAT, plus INPS contribution and stamp duty where they apply.
- One implementation serves quotes, invoices, recurring schedules, PDFs, XML and the dashboards. hive-crm's `App\Shared\Billing` tests are its specification.

### 5.2 Numbering (Kernel)

- A sequence per (series, year), stored in a table and incremented under a row lock inside the issuing transaction. A rollback releases the number, so there are no gaps. (Not a PostgreSQL `SEQUENCE`: those aren't transactional.)
- Series are configurable — one shared series for invoices and credit notes by default; separate series are supported.
- **Opening number**: the first number the app issues in a year can be set, so numbering started in another tool that year continues without a gap or a duplicate.
- **One format everywhere**: the same string in the XML `<Numero>`, the PDF, the screens and emails. (hive-crm printed `0001/2026` on PDFs and sent `2026/001` to SdI.)

### 5.3 Invoice lifecycle (Invoicing)

```
            issue                   send by PEC                 RC
  Draft ─────────────▶ Issued ───────────────────▶ Sent ─────────────▶ Delivered
    │                    │                           │
  delete              export XML                     │ MC
                         ▼                           ├─────────────▶ Not delivered (still issued: tell the client)
                   Sent manually                     │
                                                     │ NS
                                                     └─────────────▶ Rejected
                                                                        │ fixed and resent within 5 days,
                                                                        │ same number and date ─────────▶ Sent
                                                                        │
                                                                        └─ window missed ─▶ Void (issue a new invoice)
```

- **Draft**: editable, deletable, no number.
- **Issue** (one use case): allocate the number; freeze the seller and customer snapshots (fiscal identity, address, SdI addressing) onto the document; compute and freeze the totals; build the XML and validate it (schema and business rules); store the XML and the courtesy PDF in Files. From here on the content is immutable.
- **Send** by PEC (the default), or **export** the XML for upload to the Agenzia's portal and mark it sent manually. Either way it must reach SdI within 12 days of the operation — for services, of the payment — and the app counts down.
- **Delivered, Not delivered, Rejected** come from SdI's notifications ([sdi.md](sdi.md)). A rejected invoice counts as never issued; it is fixed and resent within 5 days with the same number and date, or voided and replaced by a new invoice.
- **Payment state** is separate from the lifecycle: unpaid, partially paid, paid, and overdue when past due — derived from the cash movements allocated to the invoice. Cancelling is not a state: it is a credit note for the full amount.
- **Credit notes** (TD04) reference the original invoice (`DatiFattureCollegate`) and follow the same lifecycle.
- **Payment requests** (pro forma) are not fiscal documents and never reach SdI. Services are deemed performed when paid (art. 6 DPR 633/72), so a freelancer often asks for payment first and invoices on receipt: one action turns a paid request into an invoice dated on the payment.
- **Recurring invoices**: a schedule (frequency plus an anchor day, so it never drifts) creates a draft on each date — or, opted into per schedule, issues and sends it.

### 5.4 Cash

- A **movement** is the only record of money moving: direction, amount, date, account, counterparty, category, description, and an optional **allocation** to a document of another module, stored as an opaque reference (`invoice:123`, `supplier_invoice:45`, `domain_renewal:7:2027`).
- The ledger, monthly series, year-to-date figures, category breakdowns and dashboard KPIs are **queries over movements**. There is no separate ledger table.
- **Allocated movements belong to the allocating module.** Recording or removing an invoice payment goes through Invoicing (`RecordPayment`, `RemovePayment`), which writes the movement and the invoice's cached paid amount in one transaction. Cash's own screens show allocated movements read-only, linking to their document.
- **Recurring expenses**: a schedule (frequency plus anchor day) and a "log occurrence" action that records the movement and moves the due date.
- **Cash-flow projection**: the sum of all `CashFlowSchedule` contributions. Items still pending from before the window (unpaid invoices, unlogged renewals) count in the first month and are flagged overdue — hive-crm's final semantics.

### 5.5 Managed assets

- Domain and hosting renewals each have one step rule (no-overflow months), used both by "log renewal" and by the projection, so projected dates are the dates that happen.
- Logging a renewal is idempotent per (asset, period).
- A website's subscription is a recurring invoice created through Invoicing; the website keeps the schedule's reference.

## 6. Quality bar

- **CI on every push:** Pint in test mode, Larastan at max, Pest (Unit, Feature, Arch) on PostgreSQL 18 in a service container.
- **Architecture tests** (§4.3) from phase 0.
- **Query budgets:** list pages and dashboard widgets have tests that the query count stays constant as rows grow; lazy loading is disabled outside production.
- **Golden files:** every kind of FatturaPA XML the app produces has a fixture validated against the official schema.
- **Real-world fixtures** for fiscal edge cases — anonymised invoices and SdI notifications — rather than factories.
- **Mutation testing** (`pest --mutate`) on the Kernel's money code.
- **Bug fixes start with a failing test.**

## 7. Operations

- Docker images for the app, the queue worker (Horizon) and the scheduler; PostgreSQL; Redis; Caddy for TLS.
- Secrets — PEC password, SES keys, app key — live only in the server's environment, never in the database or the repository.
- One owner account, created with `php artisan owner:create` (no default user, no seeded password), with mandatory two-factor authentication (TOTP, with recovery codes; secrets stored encrypted). Issuing, sending and voiding fiscal documents are recorded in the audit trail (the Audit module).
- Nightly backups of the database and files to S3-compatible storage, with a restore drill in the runbook. Legal archiving of invoices is separate — see [sdi.md §8](sdi.md#8-archiving-conservazione).
- Production-safety tests (no default credentials, gated operations dashboards, no dev-only packages at runtime), ported from hive-crm.

## 8. From hive-crm

- No live data migration: hive-crm is not in production. Phase 8 offers a one-off importer for reference data — contacts, websites, domains, repositories, catalog.
- Invoice numbering continues from whatever was issued elsewhere that year, via the opening number (§5.2).
- The specifications worth porting are listed in [lessons-from-hive-crm.md](lessons-from-hive-crm.md#tests-worth-porting).
