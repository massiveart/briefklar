# briefklar/pii-shield

Reversible PII redaction for [Symfony AI](https://ai.symfony.com), focused on the DACH region.

- Checksum-validated recognizers: Austrian social security number (SVNR), IBAN, German tax ID
- Context-aware: birth dates are redacted, deadlines and amounts are not
- Consistent typed placeholders (`⟦PERSON_1⟧`) that models can reason about
- Plugs into any Symfony AI agent as input/output processor, restores values in the answer
- Optional NER for names and addresses via Hugging Face, regex-only mode works offline

## Usage

```php
use Briefklar\PiiShield\PiiShield;

$shield = PiiShield::withDefaultDetectors(); // offline, regex + checksums

$redaction = $shield->redact("Herrn\nJohann Beispielmann\n\nSVNR 4568 150392");
$redaction->redactedText; // "Herrn\n⟦PERSON_1⟧\n\nSVNR ⟦SVNR_1⟧"

$answer = $shield->restore('⟦PERSON_1⟧, Ihre SVNR ist ⟦SVNR_1⟧.', $redaction);
$answer->text;                // "Johann Beispielmann, Ihre SVNR ist 4568 150392."
$answer->unknownPlaceholders; // placeholders the model invented, e.g. ['⟦IBAN_7⟧']
```

`RedactionResult` holds the original values in memory. Never log, persist or send it anywhere.

## Detectors

| Entity | Detector | How |
|---|---|---|
| `SVNR` | `AtSvnrDetector` | `\d{4} ?\d{6}`, check digit validated |
| `IBAN` | `IbanDetector` | ISO 13616 mod 97; trailing tokens like `BIC` are trimmed |
| `TAX_NUMBER` | `TaxNumberDetector` | AT Steuernummer (`46 123/4567`), DE Steuernummer (`181/815/08155`), DE Steuer-ID (checksum) |
| `EMAIL` | `EmailDetector` | regex; authority contacts kept (see below) |
| `PHONE` | `PhoneDetector` | international and national, ≥ 7 digits; authority contacts kept |
| `BIRTH_DATE` | `BirthDateDetector` | only after `geb.`, `geboren (am)`, `Geburtsdatum`; must be a real date |
| `FILE_NUMBER` | `FileNumberDetector` | only after `Zahl`, `GZ`, `Aktenzahl`, `Kundennummer`, `Verwendungszweck`, ... |
| `LICENSE_PLATE` | `LicensePlateDetector` | only after `Kennzeichen` |
| `PERSON` | `SalutationPersonDetector` | name after `Herr`/`Herrn`/`Frau` (address block or inline), titles stripped |
| `ADDRESS` | `PostalAddressDetector` | two-line `Street 12[/3]` + `postcode city` |

Validators (`AtSvnrValidator`, `IbanValidator`, `DeTaxIdValidator`) can be used on their own.

## Redaction rules

- **Overlaps:** the longer span wins, on equal length the higher confidence.
- **Consistency:** the same value always gets the same placeholder within one text, numbered per type in order of appearance.
- **Propagation:** once a value is detected, every further whole-word occurrence is redacted too (e.g. a file number repeated without keyword).
- **Surname aliases:** `Herr Beispielmann` maps to the placeholder of `Johann Beispielmann` from the address block, so the model knows it is the same person. Restore yields the full name, so `restore(redact(x))` equals `x` except that such aliases come back as the full name.
- **Not PII, kept on purpose:** amounts, deadlines and other dates, authority names, legal citations, the authority's own e-mail and phone (lines that say "uns", "einzubringen", name an authority, ...; disable with `new EmailDetector(keepAuthorityContacts: false)`), names without salutation (the signing official).

### Known limits of the offline mode

- Inline salutations take up to two capitalised words, so `Herr Muster Bescheid` redacts `Muster Bescheid`. Over-redaction is preferred to leaking half a name. Surnames are only propagated from the (reliable) address block.
- Names without salutation and single-line addresses need the NER detector (planned).

## Restore

`restore()` tolerates what models do to placeholders: case changes, inner whitespace, space instead of underscore, markdown-escaped underscores (`⟦PERSON\_1⟧`) and ASCII brackets (`[[PERSON_1]]`). Placeholders not in the vault are left as they are and reported in `unknownPlaceholders`; vault entries not used in the text are listed in `unusedPlaceholders`.

## Development

```bash
composer install
composer test       # PHPUnit
composer qa         # php-cs-fixer (@Symfony) check + PHPStan level 8
composer cs-fix     # apply coding standard
```

From the repository root, `make test` and `make qa` run the same checks for all packages.

Status: work in progress (Symfony AI & MCP Hackathon 2026). See `../../docs/tasks/A-pii-shield.md`.
