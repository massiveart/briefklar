# briefklar

> Understand official letters without handing your personal data to an LLM.

**briefklar** explains letters from authorities (tax office, district authority, social insurance) in plain language: *who wrote this, what do they want, what do I have to do, by when*. Before any text reaches a language model, a reversible PII shield replaces names, addresses, social security numbers, IBANs and file numbers with placeholders, and restores them only in the answer you see.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) on Symfony AI and the official PHP MCP SDK.

## What's inside

| Path | What | Reusable on its own |
|---|---|---|
| `packages/pii-shield` | Reversible PII redaction for Symfony AI (input/output processors), DACH recognizers with checksum validation, plus its own MCP server | Yes, designed as standalone package |
| `app/` | The briefklar web app plus the MCP server `briefklar` (`explain_letter_file`, `explain_letter`, `redact_text`) | No, it's the demo |
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

## MCP

Two MCP servers, both on the official PHP MCP SDK via `symfony/mcp-bundle`, over STDIO and Streamable HTTP (`/mcp`):

| Server | Tools | Why |
|---|---|---|
| `briefklar` (`app/`) | `explain_letter_file`, `explain_letter`, `redact_text` | `explain_letter_file` reads the letter from a folder on the server, runs OCR and redaction locally and answers with placeholders such as `⟦IBAN_1⟧`. The letter never enters the client model's context. |
| `pii_shield` (`packages/pii-shield`) | `redact_text`, `restore_text`, `mask_text`, `learn_entity_type`, `list_entity_types` | Reversible redaction as a building block for any MCP client. |

Setup for Claude Code, Claude Desktop and the MCP Inspector: [app/README.md](app/README.md) and [packages/pii-shield/README.md](packages/pii-shield/README.md).

## Roadmap (after the hackathon)

- Resolve legal citations in a letter (e.g. "§ 49 VStG") against Austria's open legal database RIS, so the explanation links to the law it quotes.

## Not legal advice

briefklar explains what a letter says. It does not give legal advice, and deadlines it extracts must be verified against the original letter.

## License

MIT
