# Changelog

All notable changes to `briefklar/pii-shield`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/) (0.x: the API may still change).

## [Unreleased]

### Fixed

- Model findings are checked in their context (handover #59, letter `03-anonymverfuegung`): addresses are extended by postcode and town, dates and times are dropped, words in legal citations kept, file numbers need a digit and a keyword, authority contacts (letterhead, "wenden Sie sich an") kept unless `keepAuthorityContacts: false` (bundle: `keep_authority_contacts`).
- "wenden Sie sich an" marks an authority contact for the e-mail and phone detectors too.

### Changed

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

[0.1.0]: https://github.com/massiveart/briefklar/releases/tag/pii-shield-v0.1.0
