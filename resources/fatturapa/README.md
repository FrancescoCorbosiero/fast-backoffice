# FatturaPA schema

`Schema_del_file_xml_FatturaPA_v1.2.3.xsd` is the Agenzia delle Entrate's XML schema for ordinary e-invoices (FPA12 and FPR12), version 1.2.3, with the `xmldsig-core-schema.xsd` it imports. Every XML the app builds, and every fixture under `tests/Fixtures/fatturapa`, is validated against it (sdi.md §4).

Taken from the public copy bundled by [fatturaelettronicaphp/FatturaElettronica](https://github.com/fatturaelettronicaphp/FatturaElettronica) (MIT), because the Agenzia's site was not reachable from the environment that set this up. When the Agenzia publishes a new version, replace the file, bump the version in `config/backoffice.php` and re-run the golden-file tests.
