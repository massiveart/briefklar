# ADR 0005: Pluggable encrypted vaults and irreversible masking

## Status
Accepted (09.11.2026). Amends ADR 0003 ("mapping stays in memory for one request"). Agreed by both workstream owners.

## Context
ADR 0003 keeps the placeholder mapping (vault) inside `RedactionResult`, in memory, for one request. Two use cases need more:

1. **Unmasking across requests.** The MCP tool `redact_text` masks in one call; the client wants to unmask the model's answer in a later call. The mapping has to survive between requests, in a storage the integrator chooses (Redis, filesystem, database, ...).
2. **Irreversible masking.** Logs, debug output and UI previews need text without PII but do not need it back, e.g. `AT61 **** **** **** 3201` or `[PERSON]`.

A persisted vault is a collection of personal data in clear text unless protected. That is exactly what pii-shield exists to avoid (GDPR Art. 5 data minimisation and storage limitation, Art. 32 security of processing).

## Decision

### Vaults
- `VaultInterface` (`store`, `fetch`, `delete`) is what `PiiShield` talks to. Optional: without a vault, behaviour is unchanged (mapping only in `RedactionResult`).
- `InMemoryVault`: process-local, TTL-aware.
- **Persistent storage only through `EncryptedVault`.** It encrypts the mapping with XChaCha20-Poly1305 (libsodium) before handing bytes to a `VaultStorageInterface`; the vault id is bound as associated data, so ciphertexts cannot be swapped between ids. Storage backends never see clear text. Shipped backend: `Psr6CacheStorage` (any PSR-6 pool, e.g. Symfony Cache with Redis, filesystem, PDO).
- **TTL is mandatory** (default 3600 s). `delete()` removes a mapping immediately (right to erasure, end of conversation).
- Vault ids are random (128 bit), unrelated to the content. Vault contents and keys are never logged; the key parameter is `#[\SensitiveParameter]`.

### Contract
- `PiiShieldInterface` unchanged.
- `RedactionResult` gets an optional trailing `?string $vaultId` (backwards compatible, `NullPiiShield` unaffected).
- New `VaultAwarePiiShieldInterface extends PiiShieldInterface`: `restoreFromVault(string $text, string $vaultId): RestoreResult` and `forget(string $vaultId): void`.

### Masking
- `PiiMaskerInterface::mask(string $text): string`, implemented by `PiiMasker`. Same detectors and overlap rules as `PiiShield` (shared `EntityExtractor`), but no vault and no way back.
- Per-type `MaskingStrategyInterface`: `PartialMask` (keeps first/last n characters, keeps spaces), `TypeLabelMask` (`[PERSON]`). Defaults: IBAN keeps 4 + 4, phone keeps the last 2, everything else is a type label.

## Rejected
- **Plain-text persistent vaults:** would make pii-shield a store of exactly the data it should protect.
- **Encryption in each storage backend:** easy to forget in a new backend; one decorator is easier to review.
- **Changing `PiiShieldInterface`:** the app (workstream B) only needs the in-request flow; a separate interface keeps that path untouched.

## Consequences
- New runtime requirements: `ext-sodium`, `psr/cache` (interfaces only).
- The integrator manages the encryption key (e.g. Symfony secret). Losing the key makes stored mappings unreadable, which is acceptable given the short TTL.
- Partial masks leak length and some characters by design; they are for logs and previews, not for sending to model providers. Providers only ever get placeholder text from `redact()`.
