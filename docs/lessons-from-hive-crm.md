# Lessons from hive-crm

In October 2026 [hive-crm](https://github.com/FrancescoCorbosiero/hive-crm) was audited and fixed on its branch [`claude/blissful-euler-g03091`](https://github.com/FrancescoCorbosiero/hive-crm/tree/claude/blissful-euler-g03091); each commit message explains a fix. These are the findings, why they happened, and the rule in this project that prevents each class. Principles (P1–P8) are in [design.md §2](design.md#2-principles).

## Fixed there, prevented here by design

| What went wrong in hive-crm | Root cause | Rule here |
|---|---|---|
| Editing an invoice or quote in the admin left its totals stale | Services set the totals; the admin's edit page bypassed them | Totals are derived inside the document, from its lines (P1) |
| VAT computed in 8 places, per line; the XML's summary didn't follow the per-rate rule SdI checks | No single money engine | One Kernel VAT engine: per-rate rounding on the summed taxable amount, integers only (P4) |
| The XML exporter ignored the business profile's default natura | Settings read on some paths only | Seller and customer snapshots frozen at issue (design §5.3) |
| The demo seeder overwrote real invoices #1 and #2 | Demo data wrote to fiscal tables with fixed numbers | Demo data never touches fiscal numbering |
| Deleting a payment or an invoice left its income in the ledger; generating an invoice from an income entry counted the cash twice | Payments copied into the ledger and synced by listeners | A payment is one cash movement (P1, design §5.4) |
| A partly paid invoice past its due date wasn't flagged overdue | Status precedence was implicit | Payment state derived with explicit, tested precedence (design §5.3) |
| A schedule on Jan 31 skipped February (→ Mar 3) — for invoices, expenses and renewals | Carbon's overflowing `addMonth()` | No-overflow month arithmetic and anchor days (P4) |
| Month labels rendered as "maggiomaggio5 2026202620262026" | ISO tokens passed to `translatedFormat()` | `isoFormat()` only; an architecture test forbids `translatedFormat()` |
| Every open quote flagged as expiring; a scheduled task's edit page crashed | Carbon 3 made `diffIn*()` signed floats | Compare dates rather than diffing them; Larastan catches float-for-int returns (P6) |
| "Issue fattura" on a contact created a €0 invoice and burned a number | Numbers allocated at creation | Numbers allocated at issue only (P2) |
| The cash-flow projection ignored recurring expenses and unpaid invoices, dropped overdue items, and was computed twice per render | Each page re-implemented the domain's maths | The `CashFlowSchedule` extension point; one step rule per schedule (design §4.4, §5.5) |
| Campaign retries never ran; rate-limit releases used up the attempts; campaigns marked sent when merely queued; duplicate SES notifications counted twice; link scanners could unsubscribe people | Queue semantics misread; no idempotency; a GET with side effects | Retry budgets by time and exceptions; idempotency keys on every webhook; POST-only unsubscribe (RFC 8058) |
| Every install's admin was `admin@hive.local` / `password`; Horizon and Pulse unreachable in production; the demo-data button crashed in production (Faker is dev-only); settings read with `env()` reset by `config:cache` | No production checks | Production-safety tests (design §7); P7 |
| Status tabs ran one COUNT per tab (18 queries per interaction); a widget ran a query per row (93 at 30 rows); the income chart ran one query per month | No query budgets | Query-budget tests; lazy loading disabled outside production (design §6) |
| The scheduler queried the database on every artisan command; failed runs always recorded exit code 1 | Eager loading in `routes/console.php`; `onFailure()` can't see the exit code | Jobs defined in code; history records the real exit code (design §4.2) |
| Cross-domain workflows carried "intents" on events and needed empty payloads to avoid loops | Events used as commands | Use cases call down; events only notify (P3) |
| The module rules were documented but broken in 66 places | Nothing enforced them | Architecture tests from the first commit (P5) |

## Found there but not fixed — requirements here

- The XML `<Numero>` (`2026/001`) differed from the PDF (`0001/2026`): one format everywhere (design §5.2).
- Issued invoices could be edited and deleted, with no draft stage: the lifecycle of design §5.3.
- Stamp duty (`DatiBollo`) was never written: [sdi.md §7](sdi.md#7-stamp-duty-imposta-di-bollo).
- The customer's `IdPaese` was hard-coded to `IT`: [sdi.md §4](sdi.md#4-building-the-xml).
- A random 5-character file-name suffix could collide, which SdI rejects: a gapless sequence ([sdi.md §3](sdi.md#3-outbound)).
- Signed supplier invoices (`.p7m`) weren't supported: [sdi.md §5](sdi.md#5-inbox).
- Every SES bounce, even a temporary one, blocked the contact for good: the bounce policy of design §4.2 (Mail).
- Income from payments was always categorised `website_subscription`: categories come from the service or the movement.
- Finding a website by host loaded every website; uptime pings ran one at a time: an indexed host column; concurrent checks.
- No static analysis: Larastan at max (P6).

## Tests worth porting

Port these as specifications: rewrite them against this project's APIs; don't copy the code. Paths are on hive-crm's branch `claude/blissful-euler-g03091`, where several of them were written.

| hive-crm test | What it specifies |
|---|---|
| `tests/Unit/Shared/LineItemsTest.php` | VAT engine and rounding |
| `tests/Feature/Domains/Documents/FatturaTotalsTest.php` | Totals derived from lines |
| `tests/Feature/Domains/Documents/FatturaPaExporterTest.php` | XML structure, per-rate summary, natura defaults |
| `tests/Feature/Domains/Documents/FatturaPaImporterTest.php` | Import of outbound and inbound XML, idempotency, export–import round trip |
| `tests/Feature/Domains/Documents/LedgerIntegrityTest.php` | Payments and the ledger never drift |
| `tests/Feature/Domains/Documents/PaymentsServiceTest.php` | Payment state and its precedence |
| `tests/Feature/Domains/Documents/RecurringFatturaTest.php` | Schedule stepping, day clamping |
| `tests/Feature/Domains/Finance/CashFlowProjectionTest.php` | Projection semantics, overdue carry-over |
| `tests/Unit/Shared/CashFlowScheduleTest.php` | Renewal step rules |
| `tests/Feature/Domains/Mail/CampaignReliabilityTest.php` | Retries, completion, no per-recipient queries |
| `tests/Feature/Domains/Mail/UnsubscribeFlowTest.php` | RFC 8058 unsubscribe |
| `tests/Feature/Domains/Mail/SesWebhookTest.php` | Signed SNS events, duplicate deliveries |
| `tests/Feature/Domains/Scheduling/ScheduleLoaderTest.php` | Lazy schedule loading, real exit codes |
| `tests/Feature/Bootstrap/ProductionSafetyTest.php` | Production safety |
| `tests/Arch/BoundariesTest.php` | The pattern for this project's architecture tests |
