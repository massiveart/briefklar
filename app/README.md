# briefklar app

Symfony application: upload, local OCR, explanation agent, UI, MCP server.

## Stack

| | Version | Why |
|---|---|---|
| Symfony | 8.1 (current stable) | `symfony/ai-bundle` 1.0.0-BETA1 allows `^7.3\|^8.0`; 8.1 needs PHP >= 8.4.1 |
| PHP | 8.4 | see `../Dockerfile` |
| Symfony AI | 1.0.0-BETA1, pinned exactly | see `../docs/adr/0004-pin-pre-release-dependencies.md` |
| Platform bridges | `ai-hugging-face-platform`, `ai-anthropic-platform`, `ai-ollama-platform`, `ai-generic-platform` (transitive, pinned too) | provider switch via env |
| UI | Twig, AssetMapper, Symfony UX Turbo | no Node build step |

## Packages

`briefklar/pii-shield` is required via a Composer path repository (`../packages/pii-shield`, symlinked).

The app depends only on `Briefklar\PiiShield\PiiShieldInterface`. Which implementation is used is decided by one alias in `config/services.yaml`:

```yaml
Briefklar\PiiShield\PiiShieldInterface: '@Briefklar\PiiShield\NullPiiShield'
```

Until task A ships, this is `NullPiiShield` (pass-through). Switching to the real shield is a config change only.

## Text extraction (`src/Extraction/`)

Everything runs locally, nothing is sent anywhere and nothing is stored (see `../docs/adr/0002-local-ocr-before-redaction.md`).

`ExtractionChain` tries all `TextExtractorInterface` services (tag `app.text_extractor`) by priority; the first usable text wins:

| Priority | Extractor | Input | How |
|---|---|---|---|
| 30 | `Pdf\PdfTextLayerExtractor` | PDF | `smalot/pdfparser`; skipped if the text layer has fewer than 50 letters (scans) |
| 20 | `Ocr\ScannedPdfOcrExtractor` | PDF | `pdftoppm` (300 dpi, max. 10 pages) into a private temp dir, Tesseract per page, temp dir removed in `finally` |
| 10 | `Ocr\ImageOcrExtractor` | PNG, JPEG, TIFF | Tesseract (`deu`) |

The MIME type is detected from the file content, not taken from the client. `OcrTextCorrector` fixes known Tesseract misreads that matter downstream (`$ 49 VStG`, `8 20 Abs. 2`, `(3 49 VStG)` -> `§ ...`). OCR output differs between Tesseract builds and CPUs (ARM container vs. x86 CI), so every observed variant is a test case. Binaries are configurable via `TESSERACT_BINARY` and `PDFTOPPM_BINARY`.

Tests build PDFs and images from `../fixtures/letters/*.txt` at runtime (`tests/Support/PdfFixtureFactory.php`), so no binary fixtures are committed. OCR tests need `tesseract` and `pdftoppm` and are skipped without them (both are in the container and in CI).

## Development

Secrets never go into git. Create your local `app/.env.local` once (ignored; `compose.yaml` loads it into the container):

```bash
echo "APP_SECRET=$(openssl rand -hex 16)" >> app/.env.local
```

Provider keys (e.g. `HUGGINGFACE_API_KEY`) go into the same file.

No PHP on the host required. PHP 8.4, Composer and Tesseract run in the app container; the Makefile in the repository root wraps everything:

```bash
make build                            # build the app image once
make install                          # composer install for packages and app
make test                             # all PHPUnit suites
make qa                               # php-cs-fixer + PHPStan level 8
make console c="debug:container"      # bin/console in the container
make composer c="require foo/bar"     # composer in app/
make sh                               # interactive shell in the container
```

With a local PHP 8.4, append `DOCKER=0` to any target. The Composer cache lives in the `composer-cache` Docker volume.
