# Roadmap

The build follows the module layers ([design.md §4.1](design.md#41-layers)): each phase only needs the ones before it. A phase is done when every box is ticked, CI is green and the app is deployable. Sessions tick boxes as they finish them.

To continue in a new Claude Code session on this repository: *"Continue the roadmap: work on the first phase that isn't done."* `CLAUDE.md` points the session at everything else.

After phase 3 the app can already issue invoices (sent manually through the Agenzia's portal). Phase 5 automates sending and receiving.

## Phase 0 — Foundation

- [x] Laravel 13 application with a Filament 5 panel; Docker development environment with PostgreSQL 18 and Redis
- [x] CI: Pint, Larastan (max), Pest (Unit, Feature, Arch) on PostgreSQL
- [x] Module skeleton and the architecture tests for the rules in design.md §4.3
- [x] Kernel: `Money`, the VAT engine (port hive-crm's `LineItemsTest`), numbering sequences (gapless, opening number), date helpers (no-overflow months, anchor days)
- [x] Single-owner login with two-factor authentication; audit trail
- [x] Files (S3-compatible disk)
- [x] Settings: business profile, invoice defaults, numbering series
- [x] Scheduling: jobs defined in code, overrides and run history in the database, a screen to see and run them
- [x] `CLAUDE.md` commands section filled in

Notes: the audit trail became its own module (`Audit`, layer 0; design.md §4.2). Pest 4 instead of Pest 5, because Pest 5 needs PHP 8.4 on every machine that runs the tests; the upgrade is listed in phase 8. The Docker development environment is written but was not built in the session that wrote it (no Docker daemon there): the first `docker compose up` may need a fix.

## Phase 1 — Contacts

- [x] Contacts with roles; fiscal identity validation (Partita IVA, codice fiscale, foreign VAT numbers); SdI addressing; email consent and suppression
- [x] `ContactPicker` component; contact page with the `ContactPanel` extension point
- [x] Merging duplicates (`ContactsMerged` event)

Notes: the fiscal identifier validators live in the Kernel (`App\Kernel\Fiscal`), because Settings validates the owner's identity with them too. Contacts are never deleted (documents point at them); duplicates are merged. Import and export of contacts, which hive-crm had, are not in the design and were left out.

## Phase 2 — Cash

- [x] Accounts, movements, categories
- [x] Ledger, monthly series, year-to-date and category analytics as queries, with query-budget tests
- [x] Recurring expenses with "log occurrence"
- [x] `CashFlowSchedule` extension point and the projection screen

Notes: the dashboard carries the year-to-date figures, the 12-month chart and the category breakdowns; the statement page shows one account's month with running balances. Cash contributes a "Movimenti di cassa" section to the contact page. Categories are reference data seeded idempotently by `db:seed`.

## Phase 3 — Invoicing core (manual sending)

- [x] Catalog
- [x] Drafts; issue (number, snapshots, frozen totals); credit notes; payment requests
- [x] Payments through Cash (`RecordPayment`, `RemovePayment`), payment state, overdue
- [x] FatturaPA XML builder validated against the official XSD; export for manual upload; "mark as sent manually"
- [x] Courtesy PDF (choose the renderer)
- [x] Stamp duty, flat-rate regime and INPS contribution, driven by settings
- [x] Recurring invoices (anchor day; drafts, or issue automatically when opted in)
- [x] Import FatturaPA files: supplier invoices, and the owner's invoices issued elsewhere this year
- [x] Contributions: receivables and recurring invoices to the cash-flow projection; invoices to the contact page

Notes: the renderer is dompdf (`barryvdh/laravel-dompdf`), as in hive-crm. The XSD is v1.2.3, taken from a public mirror because the Agenzia's site was unreachable (`resources/fatturapa/README.md`). Golden files live in `tests/Fixtures/fatturapa` (regenerate with `UPDATE_FATTURAPA_GOLDEN=1`). Payments are recorded on invoices only; a credit note settles by itself (a refund, if any, is a plain cash movement). The INPS contribution follows the VAT treatment of the invoice's largest group: **verify** with the accountant. Voiding after a rejection is phase 5.

## Phase 4 — Quotes

- [x] Quotes with their own numbering, validity, statuses and PDF
- [x] Accepting a quote creates an invoice draft or a payment request, through Invoicing

Notes: a quote is numbered when written (`PRV n/year`, non-fiscal). "Expired" is derived by comparing `valid_until` with today, never stored, and an expired quote can still be accepted late. Editing a sent quote takes it back to draft and drops the PDF it was sent with. Invoicing opened `Queries\DocumentFigures` (lines → totals, parties, legal wording), `Actions\RenderDocumentPdf` and `Filament\Components\{LinesSchema, DocumentInfolist}` so Quotes reuses the one engine and one layout.

## Phase 5 — SdI over PEC

- [x] Outbox: sending by PEC, file-name sequence, kill switch, 12-day countdown
- [x] Inbox: IMAP polling, PEC envelope parsing, RC / NS / MC handling, rejections with the 5-day resend
- [x] Supplier invoices from SdI, including signed `.p7m` files, linked to suppliers
- [x] Integration tests against a local SMTP and IMAP server
- [ ] Every **verify** item in sdi.md checked — needs the owner or the accountant; see the list below
- [ ] First-send checklist (sdi.md §9) done with the owner

Notes: the mailbox is behind `PecMailbox` (Invoicing › Integrations › Pec); the real adapter is Symfony Mailer over SMTP plus `webklex/php-imap` (pure PHP, no `ext-imap`), tests use an in-memory fake. Hosts and credentials come from `PEC_*` env vars (`config/pec.php`); the addresses and the two switches are settings (Impostazioni › SdI e PEC). The address SdI answers from is saved automatically from the first notification. Every PEC message is stored raw and recorded once (Sistema › Casella PEC); what the app can't handle is flagged for review and counted on the dashboard. The GreenMail round trip (`PecMailboxIntegrationTest`) runs in CI through a service container and is skipped where no server is reachable (`PEC_TEST_HOST`); it could not be run in the environment this phase was built in. Still to **verify** with real traffic or the accountant: the notification file-name pattern and `daticert.xml` fields (`SdiFileNames`, `PecEnvelopeParser`), the SdI reply address, the stamp-duty natura mapping, the INPS block, the legal wording, consumers and businesses abroad without a VAT number. Telling the customer about a not-delivered invoice is a dashboard item for now (the MC fires `InvoiceNotDelivered`); the email goes with phase 7's campaigns.

## Phase 6 — Managed assets

- [x] Websites: subscriptions through recurring invoices, hosting renewals, concurrent uptime checks
- [x] Domains: renewals and idempotent "log renewal"; Repositories
- [x] Contributions to the cash-flow projection and the contact page

Notes: layer 3 is `Websites`, `Domains`, `Repositories` (Domains and Repositories link to a website, so Websites comes first). Hosting renewals follow the Kernel's `Recurrence` anchored on the first renewal; domain renewals move the expiry by whole months (`Months::add`). Both "log renewal" actions allocate the movement to `hosting_renewal:{id}:{date}` / `domain_renewal:{id}:{date}`, so a period is paid once. Uptime: one `Http::pool` for every monitored site (`websites.uptime-check`, every 10 minutes), down after two failures in a row, `WebsiteDown` / `WebsiteRecovered` events for phase 7's notifications. A website's subscription goes through Invoicing's new public `CreateRecurringInvoice` / `DeactivateRecurringInvoice`. Cash opened `Queries\Categories`, `AllocatedMovements::matching` and `Filament\Components\Amounts` for the renewal forms.

## Phase 7 — Growth

- [x] Leads, with `ConvertLead` (contact, quote and website in one transaction)
- [x] Calendar: Cal.com webhooks and periodic sync
- [x] Mail campaigns: SES, rate limiting, retries, SNS deduplication, bounce policy, RFC 8058 unsubscribe

Notes: layer 4 is `Leads`, `Calendar`, `Mail`. Campaigns go out through Laravel's mailer named in `config/mail-campaigns.php` ("ses" in production; the AWS keys and the SNS topic ARN are env vars), one queued job per recipient through the `campaign-mail` rate limiter (emails per second from settings), retried on a time budget (`retryUntil`, hours from settings) so a rate-limit release never spends an attempt. "Sending" means queued; a campaign is "sent" only when every recipient reached a final state (`CompleteCampaign`, also run by `mail.reconcile`). SES events arrive as SNS notifications on `POST /webhooks/ses`: signature verified against the certificate SNS points at (amazonaws.com only), topic checked, each SNS MessageId handled once; recipients are matched by our own Message-ID (`mail.commonHeaders.messageId`), then by SES's. Bounce policy: permanent bounce or complaint suppress at once; a temporary bounce counts, suppression after the configured number. Unsubscribe: `GET /unsubscribe/{token}` shows a page and changes nothing; `POST` (RFC 8058 one-click or the page's button) revokes consent and suppresses. Sender, rate and thresholds live in Impostazioni › Campagne email. Still to **verify** on a live account: the Cal.com payload field names (`CalComPayload`) and SES's event shapes against real notifications.

## Phase 8 — Production

- [x] Production Docker images, Caddy, backups with a restore drill, deploy runbook
- [x] Production-safety tests
- [ ] PHP 8.4 everywhere and Pest 5 (the development toolchain was on 8.3 in phase 0) — the environment this was built in had PHP 8.3 only; the images and CI run 8.4; the lock moves when every machine does (runbook §7)
- [ ] Optional: import reference data from hive-crm — not started; hive-crm is not in production

Notes: `docker/prod/Dockerfile` builds two targets from one build — `app` (PHP-FPM 8.4 with production dependencies, Filament assets published, tests and docs stripped; the same image runs Horizon and the scheduler) and `web` (Caddy with `public/`). `docker-compose.prod.yml` wires them with PostgreSQL 18, Redis and the `backup` service (`pg_dump` nightly to S3-compatible storage, verified with `pg_restore --list`, pruned after `BACKUP_KEEP_DAYS`); `restore latest` runs the drill into a scratch database. `docs/runbook.md` has the first deploy, the deploy steps (migrations by hand, `horizon:terminate`), the restore drill, secrets. The images could not be built where this phase was written (no Docker daemon): build them on the server with the runbook's commands before the first deploy.
