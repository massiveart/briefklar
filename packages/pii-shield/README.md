# briefklar/pii-shield

Reversible redaction of personal data (PII) for [Symfony AI](https://ai.symfony.com): the model only sees
`⟦PERSON_1⟧`, your user gets the answer with the real values.

- **Detection:** checksum-validated patterns for DACH formats (SVNR, IBAN, tax numbers, …), plus any model you choose
  via Symfony AI Platform (local Ollama, your own GPU server, or a public provider), plus types you teach it with examples
- **Reversible:** consistent typed placeholders models can reason about; tolerant restore, invented placeholders reported
- **Irreversible masking** for logs and previews (`AT61 **** **** **** 3201`)
- **Encrypted vaults** with TTL in any PSR-6 store (filesystem, Redis, database) to restore in a later request
- **Symfony AI agent processor:** redacts the input, restores the output, rejects images
- Context-aware: birth dates are redacted, deadlines, amounts and legal citations are not

## Two ways to use it

**As a library** (PHP 8.4, Composer):

```php
use Briefklar\PiiShield\PiiShield;

$shield = PiiShield::withDefaultDetectors();
$redaction = $shield->redact("Herrn\nJohann Beispielmann\n\nSVNR 4568 150392");
$redaction->redactedText;  // "Herrn\n⟦PERSON_1⟧\n\nSVNR ⟦SVNR_1⟧"  → send this to the model
$shield->restore('⟦PERSON_1⟧, Ihre SVNR ist ⟦SVNR_1⟧.', $redaction)->text;
// "Johann Beispielmann, Ihre SVNR ist 4568 150392."
```

In a Symfony app, register `Briefklar\PiiShield\Bridge\Symfony\PiiShieldBundle` and configure `config/packages/pii_shield.yaml`:
your Symfony AI agents get redaction and restore automatically.

→ [docs/library.md](docs/library.md): bundle configuration, vaults, masking, model-based detection, Symfony AI agents, learning, detectors, rules.

**As a standalone service** (HTTP, any language):

```bash
docker build -t pii-shield packages/pii-shield
docker run -p 8080:8080 -e PII_SERVICE_TOKEN=change-me pii-shield
curl -s localhost:8080/v1/redact -H 'Authorization: Bearer change-me' -H 'Content-Type: application/json' \
  -d '{"text":"SVNR 4568 150392"}'
```

→ [docs/service.md](docs/service.md) (configuration, security) and [docs/openapi.yaml](docs/openapi.yaml) (API reference).

**As an MCP server** (Claude Desktop, Claude Code, any MCP client): tools `redact_text`, `restore_text`, `mask_text`,
`learn_entity_type`, `list_entity_types` over STDIO or Streamable HTTP (`/mcp`):

```bash
claude mcp add pii-shield -- php /path/to/briefklar/packages/pii-shield/demo/bin/console mcp:server pii_shield
```

## Demo

A local playground for everything above: paste a text, choose regex, model or both for detection (provider, model, server URL), the vault store (filesystem, SQLite, Redis) and how to mask, see the findings highlighted in the original, click a finding for details or to keep it, and restore a model answer.

```bash
make demo            # from the repository root, or: composer demo
# open http://127.0.0.1:8088
```

Runs on PHP's built-in server; the page talks only to its own local API, no web fonts, no CDN. Provider URLs and keys: copy `demo/.env.dist` to `demo/.env.local` (git-ignored) and fill in what you use, or set the same environment variables; they never reach the browser. The vault mapping is encrypted on disk under `demo/var/` (git-ignored, deleted after 15 minutes). Settings are remembered in the browser, the text never is.

## Development

```bash
composer install
composer test       # PHPUnit
composer qa         # php-cs-fixer (@Symfony) check + PHPStan level 8
composer cs-fix     # apply coding standard
```

From the repository root, `make test` and `make qa` run the same checks for all packages.

Built for the Symfony AI & MCP Hackathon 2026. Decisions: `../../docs/adr/`.
