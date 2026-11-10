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

## Packages and redaction

`briefklar/pii-shield` is required via a Composer path repository (`../packages/pii-shield`, symlinked) and registered as `PiiShieldBundle`. `config/packages/pii_shield.yaml` attaches its agent processor to both explanation agents:

- **Input:** after the system prompt is added, the processor replaces personal data in the whole request with typed placeholders (`⟦PERSON_1⟧`, `⟦IBAN_1⟧`, ...). The provider never sees the original values. Amounts, deadlines, authority names and legal references stay.
- **Output:** the structured `LetterExplanation` comes back with the original values restored, so the app code never deals with placeholders. Placeholders the model invented cannot be restored; the result page flags them.
- **Fails closed:** if redaction fails, nothing is sent and the user gets the generic error (`LetterExplainer` catches pii-shield exceptions).
- Vault and model-based detection are not configured (one request, regex and checksum detectors only); learned types are disabled.

`tests/Functional/PiiProtectionTest.php` uploads the fixture letters as text PDF and as scan, in both explanation styles, and checks the recorded provider request against `../fixtures/letters/*.expected.json`: no PII value (multi-line addresses line by line) may appear, every `must_survive` value must.

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

## Explanation agent (`src/Explanation/`)

`LetterExplainer` sends the extracted text (wrapped in `<letter>…</letter>`) to the agent `letter_explainer` from `config/packages/ai.yaml` and asks for structured output into `LetterExplanation` (shared contract with task A, change only together). pii-shield redacts the request and restores the answer inside the agent (see above). The system prompt lives in `config/prompts/explain_letter.md`: explain, no legal advice, never invent deadlines, keep pii-shield placeholders.

A second agent, `letter_explainer_easy` (`config/prompts/explain_letter_easy.md`), writes the same structured answer in "Leichte Sprache" (very short sentences, explained terms, middle dots in compounds). Users opt in with a checkbox on the upload page; `ExplanationStyle` picks the agent. Both prompts carry the same safety rules, and sender, deadline, amount and legal references stay verbatim so `DeadlineCheck` still works.

Provider and model come from env, no code change needed:

| `AI_PLATFORM` | Key in `.env.local` | Example `AI_MODEL` |
|---|---|---|
| `huggingface` (default) | `HUGGINGFACE_API_KEY` | `Qwen/Qwen3-235B-A22B-Instruct-2507` |
| `anthropic` | `ANTHROPIC_API_KEY` | `claude-sonnet-5-5` |
| `ollama` | none (`docker compose --profile local up -d`) | `qwen3:8b` |

`DeadlineCheck` verifies that the reported deadline is written in the letter (verbatim period, or an ISO date that appears as `28.10.2026`, `5.1.2027` or `14. November 2026`). If not, the result page shows a warning instead of silently trusting the model.

Tests replace only the platform (`tests/Support/ScriptedPlatform.php`, wired in `config/services_test.yaml`) with canned answers from `tests/Fixtures/explanations/`. The agent pipeline (system prompt, processors, structured output) runs for real, and every provider request is recorded, so a test can check what would leave the server.

## UI (`assets/styles/app.css`, `templates/`)

The pages follow the "Classical" design system (Claude Design): its tokens (`--color-*`, `--font-*`, `--space-*`, `--radius-*`, `--shadow-*`) and component classes (`.nav`, `.btn`, `.field`/`.input`, `.card`, `.tag`) are copied into `app.css`. The system defines one light theme. Cormorant Garamond and Lora are self-hosted from `assets/fonts/` (SIL OFL 1.1, licences next to the files), so no visitor request goes to Google Fonts. The logo (`assets/images/logo.svg`, favicon `logo-mark.svg`) is an envelope whose flap turns into a check mark, stroked in the accent, next to the wordmark "brief*klar*" in Cormorant Garamond with the text outlined as paths. The system has no error colour, so warnings and form errors use the deep accent ramp plus an icon and text. The deadline card is the most prominent element of the result page; an unverified deadline gets a dashed border and a warning.

## Languages

The app is available in German (`/de`) and English (`/en`); `/` redirects by `Accept-Language` (fallback German). The UI language is also the language of the explanation: the English page explains a German letter in English ("Easy Read" instead of "Leichte Sprache"). Sender, deadline, amount and legal references stay in the letter's original wording, so they can be found in the original and `DeadlineCheck` keeps working. The answer language is sent after `</letter>`, outside the part the model treats as data.

`ExplanationLanguage` is the single list of languages (route requirement, switcher, model instruction). Adding one: a case there, `translations/messages.<locale>.yaml` and `validators.<locale>.yaml`, and the locale in `enabled_locales` (`config/packages/translation.yaml`).

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
