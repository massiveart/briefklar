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

Needs Docker and a [Hugging Face access token](https://huggingface.co/settings/tokens) that may call Inference Providers.

```bash
git clone https://github.com/massiveart/briefklar.git && cd briefklar
echo "HUGGINGFACE_API_KEY=hf_your_token" >> app/.env.local   # git-ignored, never committed
docker compose up -d                                        # first start installs dependencies (about a minute)
open http://localhost:8080
```

Synthetic letters to try are in `fixtures/letters` (text files; save one as PDF or take a photo of a printout). Without a key the upload page says what is missing. `docker compose logs -f app` shows the first start.

**Fully local, no provider at all:** the explanation runs on Ollama.

```bash
printf "AI_PLATFORM=ollama\nAI_MODEL=qwen3:8b\n" >> app/.env.local
docker compose --profile local up -d
docker compose exec ollama ollama pull qwen3:8b               # about 5 GB, once
```

Docker on macOS has no GPU access, so the model runs on the CPU and is slow. Faster on a Mac: run Ollama natively (`brew install ollama && brew services start ollama && ollama pull qwen3:8b`), add `OLLAMA_ENDPOINT=http://host.docker.internal:11434` to `app/.env.local` and start without the profile. Details in [app/README.md](app/README.md).

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
