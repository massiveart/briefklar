# Changelog

All notable changes to `briefklar/pii-shield`. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/) (0.x: the API may still change).

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
