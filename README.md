# briefklar

> Understand official letters without handing your personal data to an LLM.

**briefklar** explains letters from authorities (tax office, district authority, social insurance) in plain language: *who wrote this, what do they want, what do I have to do, by when*. Before any text reaches a language model, a reversible PII shield replaces names, addresses, social security numbers, IBANs and file numbers with placeholders, and restores them only in the answer you see.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) on Symfony AI and the official PHP MCP SDK.

## What's inside

| Path | What | Reusable on its own |
|---|---|---|
| `packages/pii-shield` | Reversible PII redaction for Symfony AI (input/output processors), DACH recognizers with checksum validation | Yes, designed as standalone package |
| `packages/ris-client` | Resolves legal citations in a letter (e.g. "§ 49 VStG") against Austria's open legal database RIS *(stretch goal)* | Yes |
| `app/` | The briefklar web app plus an MCP server exposing `explain_letter` and `redact_text` | No, it's the demo |
| `fixtures/letters` | Synthetic letters with expected PII annotations, used for tests and the demo | |

## Quickstart (target: 5 minutes)

```bash
git clone <repo-url> briefklar && cd briefklar
cp app/.env.dist app/.env.local      # add one provider key, e.g. HUGGINGFACE_API_KEY
docker compose up -d
open http://localhost:8080
```

Fully offline variant with a local model: `docker compose --profile local up -d` (uses Ollama).

## Development

No local PHP needed: `make build && make install && make test` runs everything in the app container. See [app/README.md](app/README.md#development).

## How it works

```
upload (PDF/image)
  -> local OCR (Tesseract, never leaves the container)
  -> pii-shield: detect + replace with placeholders   [Symfony AI InputProcessor]
  -> agent: structured explanation (sender, request, action, deadline, amount)
  -> pii-shield: restore placeholders                 [Symfony AI OutputProcessor]
  -> answer shown to the user
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Not legal advice

briefklar explains what a letter says. It does not give legal advice, and deadlines it extracts must be verified against the original letter.

## License

MIT
