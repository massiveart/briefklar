# pii-shield as a standalone service

Run pii-shield as an HTTP service when the application is not PHP, or when several applications share
learned types and one place handles personal data. The API is described in [openapi.yaml](openapi.yaml)
(also served at `/v1/openapi.yaml`).

## Run

```bash
# Docker
docker build -t pii-shield packages/pii-shield
docker run -p 8080:8080 -e PII_SERVICE_TOKEN=$(openssl rand -hex 24) pii-shield

# Local, with the demo page (http://127.0.0.1:8088)
make demo
```

```bash
curl -s http://localhost:8080/v1/redact \
  -H "Authorization: Bearer $PII_SERVICE_TOKEN" -H 'Content-Type: application/json' \
  -d '{"text":"Herrn\nJohann Beispielmann\nSVNR 4568 150392"}'
# {"text":"Herrn\n⟦PERSON_1⟧\nSVNR ⟦SVNR_1⟧","vaultId":"…","expiresIn":900,"placeholders":[…]}
```

Typical flow: `redact` → send `text` to your model → `restore` the model's answer with the `vaultId`
→ optionally `DELETE /v1/vault/{vaultId}`.

## MCP

The same operations as MCP tools: `redact_text`, `restore_text`, `mask_text`, `learn_entity_type`, `list_entity_types`.
The mapping stays on the server; tools return placeholders and a `vaultId`, never the original values.

**Claude Code** (STDIO):

```bash
claude mcp add pii-shield -- php /path/to/briefklar/packages/pii-shield/demo/bin/console mcp:server pii_shield
```

**Claude Desktop** (`claude_desktop_config.json`, STDIO):

```json
{
  "mcpServers": {
    "pii-shield": {
      "command": "php",
      "args": ["/path/to/briefklar/packages/pii-shield/demo/bin/console", "mcp:server", "pii_shield"]
    }
  }
}
```

With Docker instead of a local PHP: `"command": "docker", "args": ["run", "-i", "--rm", "pii-shield", "php", "bin/console", "mcp:server", "pii_shield"]`.

**Streamable HTTP**: `http://localhost:8080/mcp` with `Authorization: Bearer $PII_SERVICE_TOKEN` (locally without a
token: localhost only).

**MCP Inspector**:

```bash
npx @modelcontextprotocol/inspector php packages/pii-shield/demo/bin/console mcp:server pii_shield
```

## How it is built

A small Symfony application in `demo/` (`MicroKernelTrait`, FrameworkBundle, MCP bundle): `Controller\ServiceController` (`/v1`), `Mcp\PiiShieldTools` (MCP),
`Controller\DemoController` (page and `/api`), `EventSubscriber\RequestGuard` (decides by the matched route: host allowlist, JSON-only, origin
check, token for /mcp, service mode, security headers). The logic is framework-free in `ServiceApi` and `DemoApi`, unit-tested on their own.
Providers, models and stores are resolved at runtime from the environment, so changes to `.env.local` apply without
clearing the cache. The Docker image installs only the server's runtime dependencies (`demo/composer.json`, ~176 MB).

## Configuration

Environment variables (or `demo/.env.local`, template `demo/.env.dist`):

| Variable | Meaning |
|---|---|
| `PII_SERVICE_TOKEN` | Bearer token. Without it only requests from localhost are answered; in service mode every request gets 503. |
| `PII_SERVICE_ONLY` | `1` = service mode (set in the Docker image): only `/v1`, no demo page, token required. |
| `PII_SERVICE_ALLOWED_HOSTS` | Extra host names the service answers to, comma-separated (e.g. behind a reverse proxy). |
| `PII_SERVICE_VAULT_STORE` | `filesystem` (default), `sqlite`, `redis` |
| `PII_SERVICE_LEARNED_STORE` | `file` (default, `demo/var/learned-types.json`) or `redis` |
| `PII_DEMO_REDIS_URL` | Redis connection for the `redis` stores |
| `PII_SERVICE_MODEL_PROVIDER` | Enables `"detection": "model" \| "both"`: `ollama`, `generic`, `mistral`, `openai`, `anthropic` |
| `PII_SERVICE_MODEL`, `PII_SERVICE_MODEL_URL` | Model name and server URL (Ollama or OpenAI-compatible server) |
| `PII_SERVICE_ACKNOWLEDGE_EXTERNAL` | `true` to allow an external provider (detection sends unredacted text there) |
| `PII_DEMO_GENERIC_KEY`, `MISTRAL_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY` | Provider keys |

## Security

- The placeholder mapping never leaves the service; it is stored encrypted with a TTL (900 s).
- The model is server configuration only; requests cannot point detection at other URLs.
- JSON only, foreign browser origins rejected, unknown `Host` headers rejected (DNS rebinding).
- Errors from model detection are generic; details stay in the server.
- `php -S` is fine for a single team or a demo; for production put a reverse proxy with TLS in front,
  or run `demo/public/index.php` under PHP-FPM or FrankenPHP.
