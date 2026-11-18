# Clarifile Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebrand the app's visible product to Clarifile and rebuild the upload and result pages in the approved dark look, with a drag-and-drop dropzone and explanatory landing sections.

**Architecture:** Same Symfony stack, no new dependency: a new `assets/styles/app.css` (dark tokens, system font stack), new Twig markup for `base`, `upload` and `result`, one new Stimulus controller (`dropzone`) wrapping the native file input, translations for all new copy in `messages.de.yaml` / `messages.en.yaml`. Locale fallback switches to English by reordering `ExplanationLanguage`.

**Tech Stack:** Symfony 8.1, Twig, AssetMapper, Stimulus 3.2, Turbo 8 (upload form keeps `data-turbo="false"`), PHPUnit 13, PHP 8.4.

**Spec:** `docs/superpowers/specs/2026-11-18-clarifile-redesign-design.md` (approved), mockup `docs/superpowers/specs/2026-11-18-clarifile-mockup.html`.

## Global Constraints

- Visible name "Clarifile" (wordmark "clari" + "file", "file" in mint). Repo, namespaces, Composer packages, MCP server id `briefklar`, route `_mcp_endpoint_briefklar`, Docker service stay unchanged.
- Colours: bg `#0B0D12`, surfaces `#0F131B` / `#11151E`, borders `#1E2330` / `#263048`, text `#E8EAF0`, muted `#A3ABBD` / `#8A93A8`, accent gradient `#7CF5C4` → `#6AA8FF`, placeholder tokens monospace on `#1A2030` in `#7CF5C4`, raw values `#FF9C9C` on `#2A1A1E`. Text contrast ≥ 4.5:1.
- Dark only, no theme switch. No third-party request from any page (no web fonts, no CDN); font = system stack `-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`, mono `ui-monospace, Menlo, monospace`.
- Copy in the tone of direction A (mockup text). Hero privacy sentence exactly: DE "Das Sprachmodell, das deinen Brief erklärt, sieht weder Namen noch Adressen noch Kontonummern." EN "The language model that explains your letter never sees names, addresses or account numbers." "What you get" headline: DE "Klarheit statt Amtsdeutsch", EN "Clarity instead of officialese". QR card tag "optional" with DE "Lässt sich in der Konfiguration einschalten." EN "Can be switched on in the configuration."
- Transparency example uses only the synthetic values of fixture 01: `Max Muster` → `⟦PERSON_1⟧`, `AT61 1904 3002 3457 3201` → `⟦IBAN_1⟧`, `BHMS-VK-2026/48213` → `⟦FILE_NUMBER_1⟧`, amount `110,00 Euro`.
- Keep every CSS hook the functional tests use: `.sender`, `.style-note`, `.placeholder-note`, `.date-note`, `.deadline`, `.deadline__value`, `.deadline__source`, `.deadline .warning`, `#deadline-heading`, `.amount`, `.actions`, `.references`, `.disclaimer`, `.payment`, `.payment-qr svg`, `.payment__details`, `.payment__iban`, `.payment .warning`, `.samples`, `.setup-note`, `.upload-status`; form fields `letter_upload[letter]`, `letter_upload[easyLanguage]`; submit labels "Brief erklären" / "Explain letter".
- Upload works without JavaScript and by keyboard; warnings keep icon + text.
- Commands (from repo root): tests `docker compose exec -T app vendor/bin/phpunit`; QA `docker compose exec -T app sh -c 'vendor/bin/php-cs-fixer fix --dry-run && vendor/bin/phpstan analyse --no-progress && bin/console lint:twig templates && bin/console lint:yaml config translations && bin/console lint:container'`; missing translations `docker compose exec -T app bin/console debug:translation en --only-missing` and `… de --only-missing` must list nothing.
- Conventional commits ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

- A file dropped next to the dropzone (anywhere on the page) must not make the browser open the PDF and leave the page: the controller prevents default `dragover`/`drop` on `window` while connected. (Task 5, manual check step.)
- Dropping several files or a `.docx`: one file only, wrong type or > 10 MB shows a message in the zone and leaves the input empty; the server's validation stays the source of truth. (Task 5.)
- Back button after submit: the page comes back from the bfcache and must not stay busy or show the old file as chosen while the input is empty (existing `pageshow` reset plus dropzone reset). (Task 5.)
- JavaScript disabled: the native file input stays visible and usable inside the zone (the visually hidden style applies only once the controller connects). (Task 5, functional test + manual check.)
- Very long file names and 375 px width: name truncates with ellipsis, no horizontal page scroll. (Task 5 / Task 7 manual check.)

---

### Task 1: English as locale fallback, EN before DE

**Files:**
- Modify: `app/src/Explanation/ExplanationLanguage.php` (case order), `app/src/Controller/LetterController.php:48`, `app/config/packages/translation.yaml` (`default_locale: en`)
- Test: `app/tests/Functional/LetterUploadTest.php` (`preferredLanguages` provider)

**Interfaces:**
- Produces: `ExplanationLanguage::locales()` returns `['en', 'de']`; first entry is the fallback (docblock already says so).

- [ ] **Step 1:** Change the provider expectations: `'empty header' => ['', '/en']`, `'unsupported falls back to english' => ['fr-FR,fr;q=0.9', '/en']`; keep `german`, `english`, `english before german`.
- [ ] **Step 2:** Run `docker compose exec -T app vendor/bin/phpunit --filter testRootRedirectsToPreferredLanguage` — expected: 2 failures.
- [ ] **Step 3:** Put `case English = 'en';` before `case German = 'de';`; in `home()` fall back to `ExplanationLanguage::English->value`; set `default_locale: en`. Check `EvaluateExplanationsCommand` and any test relying on case order (grep `cases()`); adjust only if a test breaks.
- [ ] **Step 4:** Run the full suite — expected: green.
- [ ] **Step 5:** Commit `feat(app): fall back to English for unsupported browser languages`.

### Task 2: Brand assets and name

**Files:**
- Replace: `app/assets/images/logo.svg` (mark + wordmark, ~164×34 viewBox), `app/assets/images/logo-mark.svg` (mark only, square, used as favicon)
- Modify: `app/translations/messages.{de,en}.yaml` (`app.name: Clarifile`), `app/config/packages/mcp.yaml` (`name: Clarifile` only; key `briefklar` stays; update comment)
- Test: `app/tests/Functional/LetterUploadTest.php` (new test), `app/tests/Functional/McpHttpTest.php:testAnswersAnMcpClient`

**Interfaces:**
- Produces: `asset('images/logo.svg')`, `asset('images/logo-mark.svg')` (same paths as today).

- [ ] **Step 1:** Add `testShowsTheClarifileBrand`: GET `/en` → `assertSelectorTextContains('title', 'Clarifile')`, `assertSelectorExists('header img[alt="Clarifile"]')`. In `McpHttpTest::testAnswersAnMcpClient` change the expectation to `'"name":"Clarifile"'`.
- [ ] **Step 2:** Run both — expected: FAIL.
- [ ] **Step 3:** Draw the SVGs from the mockup header: dark sheet `#141A26` with `#2F3A52` stroke and folded corner, two grey lines `#56607A`, round badge with linear gradient `#7CF5C4`→`#6AA8FF` and a `#0B0D12` check; wordmark text as SVG paths or `<text>` with the system font stack ("clari" `#E8EAF0`, "file" `#7CF5C4`, weight 700, letter-spacing −0.02em). Set `app.name` and the MCP `name`.
- [ ] **Step 4:** Run the suite — expected: green.
- [ ] **Step 5:** Commit `feat(app): Clarifile name and logo`.

### Task 3: Design tokens and page frame

**Files:**
- Replace: `app/assets/styles/app.css` (new tokens per Global Constraints, base, header/nav, footer, buttons, cards, chips, tags, warnings, focus ring, `.visually-hidden`)
- Delete: `app/assets/fonts/*` (Cormorant, Lora and their OFL files; no longer referenced)
- Modify: `app/templates/base.html.twig` (header: logo, in-page nav `#how-it-works`, `#privacy`, `#mcp`, GitHub link `https://github.com/massiveart/briefklar`, language switch "EN · DE"; new `<footer class="site-footer">`), translations `app.nav.*`, `app.footer.*`
- Test: `app/tests/Functional/LetterUploadTest.php`

**Interfaces:**
- Produces: CSS custom properties `--bg`, `--surface`, `--surface-2`, `--border`, `--border-strong`, `--text`, `--muted`, `--muted-2`, `--mint`, `--blue`, `--grad` (the gradient), `--radius`, `--radius-lg`; utility classes `.btn`, `.btn-primary` (gradient, dark text), `.chip`, `.tag`, `.tok`, `.tok--raw`, `.eyebrow`, `.section`, `.section--alt`, `.warning` (keep name; icon + text, amber/red tint that reaches 4.5:1), `.visually-hidden`. Nav links on the result page point to `path('letter_upload') ~ '#…'`.

- [ ] **Step 1:** Add `testFrameHasNavigationAndFooter`: GET `/de` → links `a[href$="#how-it-works"]`, `a[href$="#privacy"]`, `a[href$="#mcp"]`, `a[href="https://github.com/massiveart/briefklar"]` exist; `.site-footer` contains "keine Rechtsberatung"; the first language link/label in `.lang-switch` reads "EN".
- [ ] **Step 2:** Run — expected: FAIL.
- [ ] **Step 3:** Write the frame and stylesheet. Language switch labels become the uppercase codes `EN` / `DE` with `lang`/`hreflang` and a `title` holding `nativeName()`. Keep `color-scheme: dark`.
- [ ] **Step 4:** Run the suite and QA — expected: green (old pages render unstyled-but-working until Tasks 4–6).
- [ ] **Step 5:** Commit `feat(app): dark Clarifile frame and design tokens`.

### Task 4: Landing sections

**Files:**
- Modify: `app/templates/letter/upload.html.twig` (hero + sections; the form block is reshaped in Task 5), `app/translations/messages.{de,en}.yaml` (new keys under `letter.landing.*`; `letter.upload.title`/`intro` replaced by the hero copy)
- Test: `app/tests/Functional/LetterUploadTest.php`

**Interfaces:**
- Produces: section ids `how-it-works`, `privacy`, `what-you-get`, `mcp`; hero `h1`.

- [ ] **Step 1:** Update the h1 assertions (lines 47, 79) to `Official letters, clearly explained.` / `Behördenbriefe, klar erklärt.`. Add `testLandingExplainsTheFlow` (DE): `#how-it-works .step` count 4, `#how-it-works .where--model` count 1; `#privacy` contains `⟦PERSON_1⟧` and `Max Muster`; hero contains the exact privacy sentence from Global Constraints; `#what-you-get` contains "Klarheit statt Amtsdeutsch" and a `.tag` "optional"; `#mcp` contains `explain_letter_file`, `explain_letter`, `redact_text` and `claude mcp add`. Same for EN with the English strings (one test, data provider over locale).
- [ ] **Step 2:** Run — expected: FAIL.
- [ ] **Step 3:** Build the sections from the mockup (order: hero, how it works, privacy, what you get, MCP). Step texts DE (EN: same tone): 1 Lesen „Die Texterkennung läuft lokal auf dem Server. Dein Foto oder Scan verlässt ihn nicht.“ · 2 Schwärzen „Namen, Adressen, IBAN und Aktenzahl werden durch Platzhalter ersetzt.“ · 3 Erklären „Die KI sieht nur den geschwärzten Text und schreibt die Erklärung.“ (tag Sprachmodell, class `where--model`) · 4 Zurück „Deine Daten werden wieder eingesetzt, nur für dich. Gespeichert wird nichts.“. The MCP command shows `claude mcp add --transport http clarifile http://localhost:8080/mcp`. Keep `.samples`, `.setup-note` and `letter.privacy` content (move the privacy line into the dropzone hint or footer). Tokens in the privacy demo use `.tok` / `.tok--raw`.
- [ ] **Step 4:** Run suite, QA and both `--only-missing` checks — expected: green, nothing missing.
- [ ] **Step 5:** Commit `feat(app): landing sections that explain the flow`.

### Task 5: Dropzone

**Files:**
- Create: `app/assets/controllers/dropzone_controller.js`
- Modify: `app/templates/letter/upload.html.twig` (form markup), `app/assets/controllers/upload_progress_controller.js` (show steps while busy; reset dropzone on `pageshow` via event), `app/assets/styles/app.css`, translations `letter.upload.drop.*`
- Test: `app/tests/Functional/LetterUploadTest.php`

**Interfaces:**
- Consumes: `.btn-primary`, `.visually-hidden`, tokens from Task 3.
- Produces: Stimulus controller `dropzone` with targets `input`, `zone`, `name`, `error`; values `maxBytes: Number` (10485760), `types: Array` (`application/pdf`, `image/png`, `image/jpeg`, `image/tiff`), `messages: Object` (translated `too_big`, `wrong_type`, `one_file`). Root element `.dropzone` with class `is-over` during drag and `has-file` once a file is set; it dispatches `dropzone:reset` handling on `pageshow`.

- [ ] **Step 1:** Add `testDropzoneWrapsTheNativeInput` (DE and EN): `.dropzone[data-controller~="dropzone"] input[type=file][name="letter_upload[letter]"]` exists; the input has `required` and the `accept` list; `.dropzone label[for="letter_upload_letter"]` exists; the submit button is still selectable by label; the input has no `hidden` attribute and no inline `display:none` (no-JS fallback).
- [ ] **Step 2:** Run — expected: FAIL.
- [ ] **Step 3:** Markup: the zone is a `<label for="letter_upload_letter">` region containing icon, title, format/size hint, the "choose file" visual button, and the real input; the controller adds `.visually-hidden` to the input on `connect()` (so no-JS keeps the native input). On drop: take exactly one file (more → `one_file` message), check type and size (message on failure, clear input), else set `input.files = event.dataTransfer.files` and show name + human size. `window` `dragover`/`drop` default prevented while connected, removed on `disconnect()`. Keyboard: the label is focusable via the input; visible focus ring on `.dropzone:focus-within`. Busy state (upload-progress): show the four step names from Task 4 as a list with the current one animated in sequence every ~8 s, labelled as an indication only; do not claim exact progress.
- [ ] **Step 4:** Run suite + QA — expected: green.
- [ ] **Step 5:** Manual check in the browser pane (`/de`, then `/en` at 375 px): drop the sample scan via a synthetic `DataTransfer` in the console → name shows, submit works and the result renders; drop a `.txt` → message; drop outside the zone → page stays; reload with JS disabled is not available in the pane, so check by temporarily removing `data-controller` in devtools → native input visible and usable; back button after a result → zone idle and empty.
- [ ] **Step 6:** Commit `feat(app): drag-and-drop dropzone around the native file input`.

### Task 6: Result page in the new look

**Files:**
- Modify: `app/templates/letter/result.html.twig`, `app/assets/styles/app.css`
- Test: existing `LetterUploadTest`, `PaymentQrTest`, `PiiProtectionTest`, `ModelDetectionTest` (unchanged)

- [ ] **Step 1:** Restyle with the same information order and all hooks from Global Constraints: deadline card prominent (gradient border), `.deadline__source` muted quote, amount row, summary, numbered actions with gradient number badges, payment block (QR on white with quiet zone as today — scanners need contrast), questions, references as tags, disclaimer, "upload another letter" as `.btn-primary`.
- [ ] **Step 2:** Run the suite — expected: green with no test edits.
- [ ] **Step 3:** Manual check: real upload of the sample scan (Ollama) at desktop and 375 px; warning styling readable (upload the 02 text sample; if the live model raises no warning, check `.warning` on the upload page's setup note by temporarily emptying the provider key); QR scannable on the dark page.
- [ ] **Step 4:** Commit `feat(app): result page in the Clarifile look`.

### Task 7: Docs and final verification

**Files:**
- Modify: root `README.md` (title "Clarifile", tagline, one line "formerly briefklar; repository and namespaces keep that name"), `app/README.md` (product name in the intro and MCP section; `claude mcp add … clarifile …` alias in examples; server id note)
- Delete: nothing else

- [ ] **Step 1:** Update both READMEs; keep the quickstart commands byte-identical.
- [ ] **Step 2:** Full suite, QA, both `--only-missing` — expected: green, nothing missing.
- [ ] **Step 3:** Contrast check of `--muted`, `--muted-2`, `.warning` text and button text against their backgrounds (≥ 4.5:1; compute with a small script or the browser's devtools) and fix tokens if needed.
- [ ] **Step 4:** Browser pass `/en` and `/de` at 1280 px and 375 px: no horizontal scroll (`document.documentElement.scrollWidth === innerWidth`), no network request to another host (Network panel), nav anchors jump to their sections.
- [ ] **Step 5:** Commit `docs: Clarifile in the READMEs`, push the branch, open the PR with reviewer `andre-schade` (shared root README noted), test plan listing the manual checks.
