# pii-shield as a PHP library

Reference for using pii-shield inside your PHP / Symfony application. For the HTTP service see
[service.md](service.md) and [openapi.yaml](openapi.yaml).

| Need | Use |
|---|---|
| Text goes to a model, answer must get the values back | `PiiShield::redact()` / `restore()` |
| Same, but restore in a later request | `PiiShield` with a vault, `restoreFromVault()` |
| Logs, previews (no way back) | `PiiMasker::mask()` |
| Symfony AI agent | `Bridge\SymfonyAi\PiiShieldProcessor` |
| Detect beyond DACH formats, any language | `Bridge\SymfonyAi\ModelDetector` |
| Your own entity types ("mask things like this too") | `Learning\EntityLearner` |
| Symfony application | `Bridge\Symfony\PiiShieldBundle`, configured in `config/packages/pii_shield.yaml` |

Contracts: `PiiShieldInterface` (redact/restore), `VaultAwarePiiShieldInterface` (+ restoreFromVault/forget),
`PiiMaskerInterface` (mask), `Detector\DetectorInterface` (detect), `Vault\VaultInterface`,
`Vault\Storage\VaultStorageInterface`, `Learning\CustomEntityStoreInterface`.

## Symfony bundle

```php
// config/bundles.php
return [
    // ...
    Briefklar\PiiShield\Bridge\Symfony\PiiShieldBundle::class => ['all' => true],
];
```

```yaml
# config/packages/pii_shield.yaml (all keys optional, defaults shown)
pii_shield:
    types: [PERSON, ADDRESS, SVNR, IBAN, TAX_NUMBER, FILE_NUMBER, EMAIL, PHONE, BIRTH_DATE, LICENSE_PLATE, ...]
    keep_authority_contacts: true
    allowlist: []
    model:                      # detection by a model, in addition to the patterns
        enabled: false
        platform: ai.platform.ollama   # service id of a Symfony AI platform (symfony/ai-bundle)
        model: qwen3:4b-instruct
        location: local         # local | on_premise | external
        acknowledge_external_transfer: false   # required for external
        options: { think: false }
        chunk_size: 4000        # characters per model call; fits Ollama's default 4k-token context
    vault:                      # restore in a later request
        enabled: false
        key: '%env(base64:PII_SHIELD_VAULT_KEY)%'   # 32 bytes, EncryptedVault::generateKey()
        cache_pool: cache.app   # any PSR-6 pool, e.g. a Redis pool
        ttl: 3600
    learning:
        enabled: true
        path: '%kernel.project_dir%/var/pii_shield/learned_types.json'
        cache_pool: null        # use a PSR-6 pool instead of the file
    agent_processor:            # redact input / restore output of Symfony AI agents
        enabled: true
        agents: []              # agent names; empty = all agents
```

Then inject the interfaces: `PiiShieldInterface`, `VaultAwarePiiShieldInterface`, `PiiMaskerInterface`,
`Learning\EntityLearner`. Services are prefixed `pii_shield.`; add your own detectors by tagging a service
`pii_shield.detector`. The agent processor redacts the input last (after memory and other processors) and
restores the output first.

## Redact and restore

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

### Unmasking in a later request (vaults)

When masking and unmasking happen in different requests (e.g. an MCP client calls `redact_text` and restores the model's answer later), give `PiiShield` a vault. Persistent storage is only possible through `EncryptedVault` (XChaCha20-Poly1305 via libsodium); the storage backend only ever sees ciphertext. A TTL is mandatory (default 3600 s).

```php
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use Symfony\Component\Cache\Adapter\RedisAdapter;

// Any PSR-6 pool works: Redis, filesystem, PDO, ...
$vault = new EncryptedVault(
    new Psr6CacheStorage(new RedisAdapter(RedisAdapter::createConnection('redis://localhost'))),
    base64_decode($_ENV['PII_VAULT_KEY']), // 32 bytes, create with EncryptedVault::generateKey()
);
$shield = PiiShield::withDefaultDetectors($vault, vaultTtl: 900);

// Request 1
$redaction = $shield->redact($letter);
$redaction->vaultId; // random 128-bit id, hand this to the client

// Request 2
$answer = $shield->restoreFromVault($modelOutput, $vaultId);
$shield->forget($vaultId); // delete immediately when done
```

| Vault | Use |
|---|---|
| none (default) | mapping only in `RedactionResult`, one request |
| `InMemoryVault` | one process / long-running worker, TTL-aware |
| `EncryptedVault` + `VaultStorageInterface` | anything persistent; `Psr6CacheStorage` ships, implement the interface for other backends |

`fetch` of a tampered entry, an entry encrypted with another key or an entry copied to another id throws `VaultException`.

### Irreversible masking (logs, previews)

```php
use Briefklar\PiiShield\PiiMasker;

PiiMasker::withDefaultDetectors()->mask('IBAN AT61 1904 3002 3457 3201, Frau Musterfrau');
// "IBAN AT61 **** **** **** 3201, Frau [PERSON]"
```

Defaults: IBAN keeps first and last 4 characters, phone keeps the last 2, everything else becomes `[TYPE]`. Override per type: `new PiiMasker($detectors, [EntityType::Iban->value => new TypeLabelMask()])`. Partial masks leak length and some characters by design; never send masked text instead of redacted text to a model provider.

## Detection with a model of your choice

Regex and checksums cover DACH formats. Everything else (names without salutation, foreign ID numbers, health data, any language) needs a model. `ModelDetector` uses any model reachable through Symfony AI Platform and runs next to the regex detectors:

```php
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\PiiShield;
use Symfony\AI\Platform\Bridge\Ollama\Factory;

// Local or on your own GPU server (Ollama; for vLLM use the Generic bridge with its URL)
$platform = Factory::createPlatform('http://gpu-server:11434');
$model = new ModelDetector($platform, 'qwen2.5:14b', ModelLocation::OnPremise, options: ['temperature' => 0, 'think' => false]);

$shield = new PiiShield([...EntityExtractor::defaultDetectors(), $model], $vault);
```

| Where the model runs | `ModelLocation` | Bridge |
|---|---|---|
| this machine | `Local` | `symfony/ai-ollama-platform` |
| your infrastructure (e.g. GPU server with Ollama or vLLM) | `OnPremise` | Ollama or `symfony/ai-generic-platform` (OpenAI-compatible) |
| public provider | `External` + `acknowledgeExternalTransfer: true` | Mistral, OpenAI, Anthropic, … |

Detection is the one step that sees the **unredacted** text. An external provider therefore needs an explicit acknowledgement (data processing agreement, possibly a third-country transfer); without it the detector refuses to start. Values the model invents are dropped (every finding is located in the original text), reasoning output (`<think>`) is ignored, and an unusable answer throws `DetectionFailedException` instead of silently continuing. See ADR 0006.

**Long texts** are split into chunks of at most `chunkSize` characters (default 4,000: with the instructions about 2,100 tokens, so the answer still fits Ollama's default 4,096-token context), cut at a paragraph, else a line, else a space. Each chunk repeats the last fifth of the previous one, wherever the cut was, so any value shorter than that overlap is sent whole at least once. Four chunks are in flight at a time, so OpenAI-compatible servers, vLLM and hosted APIs process them concurrently (Ollama only with `OLLAMA_NUM_PARALLEL` > 1). More than `ModelDetector::MAX_CHUNKS` (50, about 200,000 characters at the default size) fails closed with `DetectionFailedException` before anything is sent. A value found in any chunk is redacted in the whole text. Cap the answer length through the platform options (`max_tokens` for OpenAI-compatible servers, `num_predict` for Ollama; e.g. 1,024 without reasoning): small models occasionally repeat their findings until the context is full, which takes many times longer; a cut answer fails closed. Smaller chunks also help recall: with `qwen3:8b`, three letters (3,100 characters) in one call missed an address, a licence plate and two names that 1,500-character chunks found.

Tested end-to-end with `qwen3.8:27b` on a local Ollama: Czech passport number, credit card, health data, IP address and a Czech phone number were found; amounts, deadlines and legal citations were kept.

## Symfony AI agents

Register one `PiiShieldProcessor` as input **and** output processor. The provider only sees placeholders; the agent's result comes back with the original values.

```php
use Briefklar\PiiShield\Bridge\SymfonyAi\PiiShieldProcessor;
use Briefklar\PiiShield\PiiShield;
use Symfony\AI\Agent\Agent;

$pii = new PiiShieldProcessor(PiiShield::withDefaultDetectors());
$agent = new Agent($platform, 'claude-sonnet-5-5', inputProcessors: [$pii], outputProcessors: [$pii]);

$result = $agent->call($messages)->getResult(); // placeholders restored
$result->getMetadata()->get(PiiShieldProcessor::METADATA_KEY)['unknown_placeholders']; // invented by the model
```

- **Input:** all text in system, user, assistant and tool messages is redacted in one pass, so the same value has the same placeholder across messages. Images, audio, video, documents and URLs are rejected with `UnsupportedContentException`: extract text locally (OCR) and send text only. System prompt templates are passed through; don't put personal data into template variables.
- **Output:** `TextResult` and `ObjectResult` are restored, structured output DTOs deeply (readonly properties included, dates and enums untouched).
- **Not yet:** streamed results are passed through unrestored. Tools receive placeholders as arguments.
- Tested against `symfony/ai-agent` / `symfony/ai-platform` **1.0.0-BETA1** (pinned, see ADR 0004), including a test that inspects the exact payload sent to a mocked platform.

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
| `PERSON` | `SalutationPersonDetector` | name after `Herr`/`Herrn`/`Frau` (address block or inline), titles stripped; OCR-tolerant: up to two blank lines, split initial (`J ohann`) |
| `ADDRESS` | `PostalAddressDetector` | `Street 12[/3]` + `postcode city` on the next line, up to two blank lines in between (OCR) |

Validators (`AtSvnrValidator`, `IbanValidator`, `DeTaxIdValidator`) can be used on their own.

## Redaction rules

- **Overlaps:** the longer span wins, on equal length the higher confidence.
- **Consistency:** the same value always gets the same placeholder within one text, numbered per type in order of appearance.
- **Propagation:** once a value is detected, every further whole-word occurrence is redacted too (e.g. a file number repeated without keyword).
- **Surname aliases:** `Herr Beispielmann` maps to the placeholder of `Johann Beispielmann` from the address block, so the model knows it is the same person. Restore yields the full name, so `restore(redact(x))` equals `x` except that such aliases come back as the full name.
- **Not PII, kept on purpose:** amounts, deadlines and other dates, authority names, legal citations, the authority's own e-mail and phone (lines that say "uns", "einzubringen", name an authority, ...; disable with `new EmailDetector(keepAuthorityContacts: false)`), names without salutation (the signing official).

### Known limits of the offline mode

- Inline salutations take up to two capitalised words, so `Herr Muster Bescheid` redacts `Muster Bescheid`. Over-redaction is preferred to leaking half a name. Surnames are only propagated from the (reliable) address block.
- Names without salutation and single-line addresses need model-based detection (`ModelDetector`).

## Restore

`restore()` tolerates what models do to placeholders: case changes, inner whitespace, space instead of underscore, markdown-escaped underscores (`⟦PERSON\_1⟧`) and ASCII brackets (`[[PERSON_1]]`). Placeholders not in the vault are left as they are and reported in `unknownPlaceholders`; vault entries not used in the text are listed in `unusedPlaceholders`.


## Learn new entity types

Teach pii-shield types it does not know, from examples. A pattern is derived from the examples
(`VN-2026-000417` + `VN-2025-998812` → `VN-\d{4}-\d{6}`); words without structure become model-only types
that the model detector learns from the examples.

```php
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Learning\JsonFileCustomEntityStore;
use Briefklar\PiiShield\PiiShield;

$learner = new EntityLearner(new JsonFileCustomEntityStore('/var/lib/pii-shield/learned-types.json'));
$result = $learner->learn('INSURANCE_NUMBER', 'Polizzennummer einer Versicherung', ['VN-2026-000417', 'VN-2025-998812']);
$result->type->pattern; // "VN-\d{4}-\d{6}"
$result->note;          // set if only the model can learn it (plain words)

$shield = new PiiShield([...EntityExtractor::defaultDetectors(), $learner->detector()]);
$shield->redact('Polizze VN-2024-123456')->redactedText; // "Polizze ⟦INSURANCE_NUMBER_1⟧"

$learner->preview(/* same arguments */); // validate without saving
$learner->forget('INSURANCE_NUMBER');
```

- Stores: `JsonFileCustomEntityStore` (default, mode 0600, atomic writes) or `CacheCustomEntityStore` (any PSR-6 pool, e.g. Redis).
- `ModelDetector(..., learnedTypes: $store)` teaches learned types to the model as few-shot examples.
- Examples are stored: synthetic or anonymised values only. Descriptions and examples are single-line and length-limited;
  hand-written patterns follow a restricted grammar (no repeated groups, at most one unbounded repetition, no anchors,
  backreferences or lookarounds), so a learned type cannot slow down or break redaction.

## Errors

| Exception | When |
|---|---|
| `Exception\VaultException` | stored mapping unreadable (tampered, wrong key) or unknown/expired in `restoreFromVault()` |
| `Exception\DetectionFailedException` | the detection model failed; fails closed |
| `Exception\UnsupportedContentException` | an agent message contains images, audio, documents |
| `Exception\LearningFailedException` | a type cannot be learned (the message says why) |
| `\InvalidArgumentException` | invalid configuration (TTL, key length, external model without acknowledgement) |
