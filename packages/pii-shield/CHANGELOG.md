# Changelog

All notable changes to `clarifile/pii-shield`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/) (0.x: the API may still change).

## [Unreleased]

### Added

- Bundle: `vault.storage` and `learning.store` take the service id of your own `VaultStorageInterface` / `CustomEntityStoreInterface` (database, S3, ...). `VaultStorageInterface` is aliased to the configured storage. Setting one of them together with `cache_pool` is rejected.
- `Learning\LockingCustomEntityStore`: wraps any learned-type store and locks writes with `symfony/lock` (optional dependency), so concurrent `learn()` calls no longer overwrite each other. Bundle: `learning.lock` takes the `LockFactory` service id.

### Fixed

- Line endings and spaces no longer hide values: `\r\n`, a lone `\r`, no-break, figure, thin and narrow no-break spaces are normalized for detection (one code point for one, so offsets, vault values and restore keep the original characters). CRLF address blocks and `AT61\u{00A0}1904 ...` were missed before.
- IBAN and SVNR split by a line break are detected; SVNR with a hyphen (`4568-150392`) too.
- Authority contacts are judged per sentence instead of per line: in a one-line paragraph, "Sie haben Einspruch erhoben. Ihre Telefonnummer ..." kept the recipient's number. Abbreviations (`Abt.`, `Tel.`, ...) and initials don't end a sentence.
- Mobile numbers and "Ihre Telefonnummer/Rufnummer/E-Mail-Adresse" are never kept as authority contacts, also for model findings: "Ihren Einspruch haben wir unter 0664 ... notiert" kept the number. Mobile means the common AT blocks (0650, 0660, 0664, 0676–0678, 0680, 0681, 0688, 0699), DE and CH only with the country code; landlines such as 0662 Salzburg, 06542, 07612, Vienna 01 5.. or 069 Frankfurt stay authority contacts.
- Authority e-mails under a second-level domain (`bh-musterstadt.gv.at`, `.co.at`) are matched against the letterhead; the label "gv" was compared before.
- An SVNR with a hyphen or line break needs a valid DDMMYY: file numbers (`VWA-2026-100084`) and a date's year followed by a number on the next line were taken for an SVNR.
- The sentence lookup for authority contacts reads at most 400 bytes on each side, so one-line OCR text with many contacts is linear again (was quadratic).
- `restore()` throws a `RuntimeException` when the placeholder regex fails (e.g. JIT stack limit on an unclosed `[[` followed by thousands of tokens) instead of returning an empty text.
- Error messages no longer carry personal data: `ModelDetector` names the provider's exception class instead of its message (providers may echo the request; the message stays in `getPrevious()` for server logs), and learning errors name the position of an example (`example 2`, `counter-example 1`) instead of its value. Both reach HTTP and MCP clients.
- Birth dates after "geb. am"; names and streets with capitals beyond `A-ZÄÖÜ` (`Čermáková`, `Željko Šarić`).
- Learned types with digits in their name (`VN2_NUMBER`) are restored; the placeholder parser accepted letters only.
- Docs: `restore_text` returns original values to the MCP client (docs said never); the demo's MCP instructions say so. New note on the Symfony profiler, which records the unredacted agent input.
- `PiiShieldBundleTest` no longer fails at random when the generated vault key contains a `%name%` sequence (handover #74): the test passes the key through an env var, as documented. The `vault.key` config info and `docs/library.md` say that a literal key must escape `%` as `%%`.
- Model findings are checked in their context (handover #59, letter `03-anonymverfuegung`): addresses are extended by postcode and town, dates and times are dropped, words in legal citations kept, file numbers need a digit and a keyword, authority contacts (letterhead, "wenden Sie sich an") kept unless `keepAuthorityContacts: false` (bundle: `keep_authority_contacts`).
- "wenden Sie sich an" marks an authority contact for the e-mail and phone detectors too.

### Changed

- **BC break:** model detection needs an explicit location. Bundle: `model.location` is required when `model` is enabled (was `local` by default, so an external provider could run without `acknowledge_external_transfer`). `ModelDetector`: the `$location` argument has no default.
- `ModelDetector` asks once more for a chunk whose answer is unusable (small models sometimes repeat their findings until the answer is cut off; answers vary under concurrent load). Unusable twice still fails closed.
- Demo: answers of self-hosted models are capped at 1,024 tokens in fast mode (`num_predict` / `max_tokens`).

- `ModelDetector` default chunk size 6,000 → 4,000 characters: a 6,000-character chunk took 3,079 of the 4,096 tokens Ollama uses by default on GPUs under 24 GB, too little room for answers on texts with much personal data.

## [0.1.0] - 2026-11-11

First release, built for the Symfony AI & MCP Hackathon 2026.

### Added

- **Reversible redaction** (`PiiShield`): typed placeholders (`⟦PERSON_1⟧`), the same value always gets the same placeholder, tolerant restore that reports invented and unused placeholders.
- **Masking** (`PiiMasker`): irreversible, per type (`PartialMask`, `TypeLabelMask`), for logs and previews.
- **Detectors** for DACH formats, with checksum validation where one exists: AT social security number, IBAN, AT/DE tax numbers and DE tax ID, e-mail, phone, birth date (with keyword), file numbers, licence plates, names after a salutation, postal addresses. OCR-tolerant address blocks (blank lines, split initials).
- **Authority contacts** (e-mail, phone of the sending authority) are kept by default; amounts, deadlines and legal citations are never redacted.
- **Propagation**: further occurrences of a found value and surnames of full names get the same placeholder.
- **Vaults**: in-memory and encrypted (XChaCha20-Poly1305, `EncryptedVault`) on any PSR-6 pool, with TTL.
- **Model-based detection** (`ModelDetector`) through any Symfony AI platform: local, on-premise or external (external needs an explicit acknowledgement). Findings are grounded in the text; long texts are split into overlapping chunks, at most 4 requests in flight, at most 50 chunks (fail closed).
- **Learning** new entity types from examples (`EntityLearner`): a safe pattern grammar, bounded-time patterns, JSON file or PSR-6 store.
- **Symfony AI integration**: `PiiShieldProcessor` as input and output processor of agents, including structured output.
- **Symfony bundle** (`PiiShieldBundle`, `pii_shield:` configuration).
- **Standalone server**: HTTP API (`/v1`, OpenAPI 3.1), MCP server (`redact_text`, `restore_text`, `mask_text`, `learn_entity_type`, `list_entity_types`) over STDIO and Streamable HTTP, demo page; Docker image and Docker-based development.

### Quality

- Linear time for long and crafted texts, guarded by a growth test.
- PHPStan level max with strict rules, php-cs-fixer `@Symfony`, mutation testing with Infection (MSI ≥ 77 %), `composer audit` in CI.

[0.1.0]: https://github.com/massiveart/clarifile/releases/tag/pii-shield-v0.1.0
