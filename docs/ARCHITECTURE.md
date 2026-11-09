# Architecture

## Flow

```
[Browser] --upload--> [app: LetterController]
                         |
                         v
                  [OcrExtractor]  Tesseract (deu), local only
                         |  plain text
                         v
              [ExplainLetterAgent]  Symfony AI Agent
                 |  InputProcessor:  PiiShieldInputProcessor   (pii-shield bridge)
                 |  -> provider (Hugging Face / Anthropic / Ollama), sees placeholders only
                 |  OutputProcessor: PiiShieldOutputProcessor  (restore)
                         v
              [LetterExplanation]  structured output (DTO)
                         v
                     [Twig UI]

MCP server (app, mcp/sdk):  explain_letter(text) / redact_text(text)
Stretch: ris-client resolves citations like "§ 49 VStG" as an agent tool
```

## Shared contract (A <-> B)

```php
interface PiiShieldInterface
{
    public function redact(string $text): RedactionResult;            // text with placeholders + vault
    public function restore(string $text, RedactionResult $result): RestoreResult;
}
```

`RedactionResult` carries the redacted text and the mapping placeholder -> original. It is never logged and never sent to a model provider.

Optional, for unmasking across requests (e.g. MCP `redact_text` followed by a later restore): `PiiShield` can store the mapping in a vault and set `RedactionResult::$vaultId`. `VaultAwarePiiShieldInterface` adds `restoreFromVault(text, vaultId)` and `forget(vaultId)`. Persistent storage is only possible through `EncryptedVault` (libsodium, mandatory TTL); any PSR-6 cache works as backend. See ADR 0005.

Irreversible masking for logs and previews (`AT61 **** **** **** 3201`, `[PERSON]`): `PiiMaskerInterface::mask(text)`, same detectors, no vault.

Placeholder format: `⟦PERSON_1⟧`, `⟦SVNR_1⟧`, `⟦IBAN_1⟧`. Rationale: unusual brackets survive markdown and are rarely "corrected" by models; same entity value always maps to the same placeholder within one document, so the model can reason about "the same person".

## What counts as PII here

| EntityType | Detection | Validation |
|---|---|---|
| PERSON | Hugging Face token classification (NER), fallback: salutation heuristics ("Herr/Frau ...") | none |
| ADDRESS | NER + postcode/street heuristics | AT/DE postcode format |
| SVNR (AT) | regex `\d{4} ?\d{6}` | check digit (weights 3,7,9,0,5,8,4,2,1,6 mod 11) |
| IBAN | regex | ISO 13616 mod 97 |
| TAX_NUMBER (AT Steuernummer, DE Steuer-ID) | regex | DE Steuer-ID check digit |
| FILE_NUMBER (Aktenzahl, Geschäftszahl, Kundennummer) | context keywords + regex | none |
| EMAIL, PHONE | regex | none |
| BIRTH_DATE | date only when preceded by "geb.", "geboren", "Geburtsdatum" | valid date |

**Explicitly not PII** (must survive, otherwise the explanation is useless): amounts, deadlines and other dates, authority names, legal citations (§ ...), license plate *only if* user opts in (default: redact).

## Decisions

See `docs/adr/`.
