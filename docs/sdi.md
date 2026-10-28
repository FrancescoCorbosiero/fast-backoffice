# Electronic invoicing: FatturaPA through SdI, over PEC

This is the part of the app with legal consequences. It reflects the Agenzia delle Entrate's technical specifications and guidance as understood at design time. Items marked **verify** must be confirmed against the official documentation, or with the accountant, before phase 5 ships.

## 1. Scope (v1)

- **Outbound**: invoices (TD01) and credit notes (TD04) in the FPR12 format, for businesses and consumers. Payment requests (pro forma) are not fiscal and never go to SdI.
- **Inbound**: supplier invoices delivered by SdI, with SdI's metadata.
- **Regimes**: flat-rate (RF19: no VAT) and ordinary (RF01: VAT rates, withholding tax where it applies).
- **Out of scope**: the public administration (FPA12), self-invoices (TD16–TD19), batches of several invoices in one file.

## 2. Channel: PEC, with no accreditation

SdI accepts invoice files attached to PEC messages and replies by PEC. That channel needs no accreditation and no agreement — only a PEC mailbox. The web-service channel (SDICoop) would need formal accreditation, interoperability tests and certificates: not worth it for one sender.

- **Mailbox**: a PEC account used only by the app, with SMTP (to send) and IMAP (to read). Its address and SdI's address are settings; its password is a secret.
- **SdI's address**: the first file goes to `sdi01@pec.fatturapa.it`. SdI answers from the address to use for every later file, and the app stores it from that first notification. **verify** on the first send.
- **One file per message.**
- **No digital signature**: FPR12 invoices don't need one (public-administration invoices do).
- **No test environment**: SdI's test environment serves the accredited channels only. Validation before every send (§4) and the first-send checklist (§9) take its place.

## 3. Outbound

1. **Issue** (design.md §5.3): number, frozen snapshots and totals, XML built and validated, files stored.
2. **File name**: `IT{IdCodice}_{progressivo}.xml`, where `IdCodice` is the sender's Italian VAT number or codice fiscale and `progressivo` is up to 5 alphanumeric characters, unique for that sender. It comes from a gapless sequence written in base 36 — never from randomness, because SdI rejects a file name it has already seen. The same value goes in `ProgressivoInvio`.
3. **Send**: one PEC message per file, recorded in an outbox (message id, file name, time). The invoice moves to *Sent*.
4. **Deadline**: 12 days from the operation — for services, from the payment. The dashboard counts down issued invoices not yet sent.
5. **Kill switch**: automatic sending can be turned off in settings. *Export XML* and *Mark as sent manually* always work, for upload through the Agenzia's portal.

## 4. Building the XML

- Built with `XMLWriter`, never by string concatenation. The official XSD is bundled with its version pinned, and every build — and every test fixture — is validated against it.
- **Seller** (`CedentePrestatore`) and **customer** (`CessionarioCommittente`) come from the snapshots frozen at issue.
- **Customer addressing**: their 7-character `CodiceDestinatario` when they have one; `0000000` plus `PECDestinatario` when they gave a PEC; `0000000` alone for consumers; `XXXXXXX` for customers abroad.
- **Customers abroad**: `IdPaese` is their country and `IdCodice` their VAT number (hive-crm hard-coded `IT`); the postcode is `00000`. **verify** the edge cases (consumers abroad, customers without a VAT number).
- **Totals**: `DettaglioLinee` from the lines; `DatiRiepilogo` with one block per (rate, natura) group from the Kernel's VAT engine — the same figures the PDF shows.
- **Natura** is required on every 0% line; the default comes from settings.
- **Flat-rate regime (RF19)**: no VAT, natura N2.2, no withholding tax, and the regime's legal wording on the courtesy PDF as a configurable text (default: the standard wording citing art. 1, commi 54–89, L. 190/2014). **verify** the wording.
- **INPS 4% contribution** (*rivalsa*) for professionals registered with the INPS gestione separata, when enabled in settings: `DatiCassaPrevidenziale` with `TipoCassa` TC22. **verify**.
- **Numero**: the same string shown everywhere (design.md §5.2).
- **Stamp duty**: see §7.

## 5. Inbox

A scheduled job reads the PEC mailbox over IMAP every few minutes. Every PEC message is an envelope: `daticert.xml` (the PEC certification data) and `postacert.eml` (the original message, carrying the attachments). Each message is processed exactly once (by its Message-ID), flagged when done, and never deleted.

| Message | What the app does |
|---|---|
| The PEC provider's own acceptance and delivery receipts | Records them as transport events on the outbox entry (the file left; it reached SdI's mailbox). Not an SdI outcome. |
| SdI **RC** — ricevuta di consegna | *Delivered*. Stores SdI's identifier and the delivery time. |
| SdI **NS** — notifica di scarto | *Rejected*. Stores the error list (code, description) and starts the 5-day resend countdown. |
| SdI **MC** — mancata consegna | *Not delivered*: the invoice **is** issued and waits in the customer's area on the Agenzia's portal. The app creates a task to tell the customer, with the courtesy PDF. |
| A supplier invoice (`.xml`, or `.xml.p7m` when signed) plus SdI's metadata file (`MT`) | Removes the signature envelope, parses the invoice, creates a supplier invoice (a payable) and links — or creates — the supplier by VAT number or codice fiscale. Idempotent per SdI identifier. |
| Anything else | Kept and flagged for review. |

Notifications are matched to invoices by file name, then by SdI's identifier. Their attachments are named `{file name}_{type}_{n}.xml`, e.g. `IT01234567890_00001_RC_001.xml`. **verify** against real notifications before relying on the pattern.

## 6. Rejections

- SdI rejects files for schema errors, invalid identifiers, duplicates and more. Each notification lists error codes and descriptions; the app shows them verbatim, with a hint where it has one.
- Validation before sending (§4) aims to make rejections rare: the schema, the Partita IVA check digit and codice fiscale checksum, no future dates, no duplicate number in the year, unique file names.
- **Resend rule**: a rejected invoice counts as never issued. The corrected file is sent within 5 days of the rejection with the **same number and date**; after that, the invoice is voided and a new one issued (Agenzia delle Entrate, circolare 13/E of 2018). The screens make both paths explicit.

## 7. Stamp duty (imposta di bollo)

- €2 on an invoice whose amounts without VAT exceed €77.47.
- Due for natures N1, N2.1, N2.2, N3.5 and N4 (excluded, outside the scope of VAT, flat-rate, letters of intent, exempt); not due for exports and similar operations (N3.1–N3.4, N3.6), reverse charge (N6.x), or VAT paid in another EU country (N7). **verify** this mapping.
- The XML declares it in `DatiBollo` (`BolloVirtuale` = `SI`, `ImportoBollo` = `2.00`), and the PDF says so.
- Whether the €2 is charged to the customer, as an extra line excluded from VAT, is a setting.
- The Agenzia computes each quarter's amount from the invoices it received. The app shows the quarter's count and total, so the figure can be checked before paying.

## 8. Archiving (conservazione)

Keeping the XML in the app, or in backups, is not legal archiving. The owner joins the Agenzia delle Entrate's free conservation service on Fatture e Corrispettivi (once, online), which preserves the invoices that pass through SdI — or uses a certified conservator. The app still keeps everything (XML, notifications, PEC envelopes) for search and reference.

## 9. Setup and first send (owner checklist)

1. A PEC mailbox for the app, with SMTP and IMAP enabled; its credentials in the server's secrets.
2. Recommended: on Fatture e Corrispettivi, register that PEC as the receiving address (*indirizzo telematico*), so every supplier invoice arrives there. A receiving address registered earlier with another provider takes precedence until it's changed.
3. Join the free conservation service (§8), if not already done.
4. Fill in the business profile, and the opening invoice number for the current year.
5. First send: a real, small invoice to a customer you can call. Check that the RC arrives and SdI's reply address is stored, then turn on automatic sending.

## 10. Testing

- **Unit**: the XML builder against golden files, every fixture validated against the official XSD; identifier validators; the stamp-duty and natura rules; the numbering and file-name sequences.
- **Parsers**: fixtures of real, anonymised PEC envelopes, SdI notifications (RC, NS, MC) and signed supplier invoices.
- **Integration**: send and receive against a local SMTP and IMAP server in CI (e.g. GreenMail), so the whole loop runs without touching SdI.
- No simulation of SdI's behaviour beyond recorded fixtures.
