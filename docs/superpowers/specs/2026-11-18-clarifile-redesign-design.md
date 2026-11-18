# Clarifile redesign (app)

Date: 18.11.2026 · Owner: app (c00n84) · Deadline: feature freeze Fri 20.11.2026
Approved mockup: [`2026-11-18-clarifile-mockup.html`](2026-11-18-clarifile-mockup.html) (open in a browser; German copy, dark look)

## Intent

The app works, but for the hackathon jury it looks like a bare form. The owner wants it to look like a real, professional product with "everything around it": a prominent drag-and-drop upload, and sections that explain what the tool is and what happens to the letter behind the scenes, step by step, so the user can see that no personal data reaches the language model. The German product name "briefklar" is too German for an international jury, so the product gets a new name and logo.

Success: a juror lands on the page, understands within seconds what the tool does and why it is safe, drops a sample letter on the page and gets a result page in the same look.

## Decisions made with the owner

- **Name: Clarifile.** Visible product name only: logo, page titles, UI texts, README, video, MCP server display name. Repository name, PHP namespaces (`Briefklar\…`), Composer package names and the MCP server id (`briefklar`, used in the route `_mcp_endpoint_briefklar` and `bin/console mcp:server briefklar`) stay unchanged.
- **Look: direction C** (dark, technical): background `#0B0D12`, surfaces `#0F131B`/`#11151E`, borders `#1E2330`/`#263048`, text `#E8EAF0`, muted `#A3ABBD`/`#8A93A8`, gradient accent mint `#7CF5C4` → blue `#6AA8FF`, monospace tokens for placeholders. Values as in the mockup; see "left to the builder" for contrast tuning.
- **Copy: tone of direction A** (calm, plain, user-first), as in the mockup. The hero's privacy sentence is worded so it is true in every configuration (with `PII_MODEL_DETECTION=1` a local model does read the unredacted letter to find names): "Das Sprachmodell, das deinen Brief erklärt, sieht weder Namen noch Adressen noch Kontonummern." / "The language model that explains your letter never sees names, addresses or account numbers." Step 2 "redact" stays tagged *local* (detection always runs locally, ADR 0006). Another change agreed after the mockup: the "what you get" headline is "Klarheit statt Amtsdeutsch" / "Clarity instead of officialese" (the earlier "you can rely on it" overpromised next to "compare with the original").
- **Logo:** document with folded corner and a round check badge in the mint→blue gradient (mockup header). Wordmark "clari**file**" with "file" in mint. Needs an SVG logo, a mark-only SVG for the favicon.
- **Single landing page** with in-page navigation, plus the existing result page in the same look. Sections, in order:
  1. Hero: headline "Behördenbriefe, klar erklärt." / "Official letters, clearly explained.", lead text, large dropzone (icon, "drop your letter here", formats and "not stored", file button, easy-language checkbox, three chips), sample letter links below.
  2. How it works: four steps (read, redact, explain, restore), each tagged *local* or *language model*.
  3. Privacy / transparency: side by side, a letter excerpt with name, IBAN and file number vs. the redacted text sent to the model. Static, synthetic example (letter 01 values), not live.
  4. What you get: deadline/amount/steps, checked deadlines, payment QR code. The QR card carries a small "optional" tag and the line "Lässt sich in der Konfiguration einschalten" / "Can be switched on in the configuration", because `PAYMENT_QR` is off by default (decided with the owner after the builder check; the card is static, no config flag in the template).
  5. MCP: short text plus the `claude mcp add` command and the three tool names. Static; `explain_letter_file` needs `MCP_LETTER_DIR` (README), the tool says so itself when it is not set.
  6. Footer: product, hackathon, MIT, "an explanation, not legal advice", GitHub link (public repo `massiveart/briefklar`).
- **Language:** `/` uses the browser's preferred language among de/en; if neither matches, **English** (today: German). `default_locale` becomes `en`. The language switch shows "EN · DE".
- **How it is built: approach 1** (below).

## Approaches considered

1. **Own CSS on the existing stack (chosen).** Replace `assets/styles/app.css` with a new stylesheet; dropzone as a Stimulus controller around the existing file input; fonts self-hosted. No new dependency, no build step, quickstart unchanged.
2. **Tailwind via the Symfony bundle.** Fast to reproduce mockups, but a new package and a build step on first start, more that can fail in the jury's 5-minute setup two days before the freeze.
3. **UI kit (Bootstrap).** Fastest, but looks generic, against "cool and distinctive".

## Goals

- New look on the upload page and the result page, desktop and phone (no horizontal scroll at 375 px).
- Drag-and-drop upload that also works by click, by keyboard and without JavaScript.
- The explanatory sections in German and English (Symfony Translation, as today).
- New name and logo everywhere a person sees the product name.

## Non-goals

- No renaming of repo, namespaces, packages, MCP server id, Docker service.
- No light theme / theme switch (direction C is dark only).
- No change to the explanation pipeline, `LetterExplanation`, pii-shield, the MCP tools' behaviour.
- No live "what the model sees" for the user's own letter (the transparency section is a static example).
- No separate pages for privacy or MCP.

## Anti-goals (a failure even if it works)

- Personal data in the page: the transparency example uses only the synthetic values from `fixtures/letters/01-strafverfuegung`.
- Any third-party request from the page (Google Fonts, CDNs, analytics): fonts and icons are local, as today (GDPR).
- A dropzone that breaks the upload without JavaScript, or for keyboard and screen reader users.
- Claims the app cannot keep (e.g. "reliable", "legally checked").
- Breaking the 5-minute quickstart (no new build step, no new service).

## Constraints

- Symfony 8.1, Twig, AssetMapper, Stimulus, Turbo (upload form keeps `data-turbo="false"`). PHP 8.4, PHPStan level 8, php-cs-fixer `@Symfony`.
- Functional tests select these and must keep working (rename only with the tests): `.sender`, `.style-note`, `.placeholder-note`, `.date-note`, `.deadline`, `.deadline__value`, `.deadline__source`, `.deadline .warning`, `#deadline-heading`, `.amount`, `.actions`, `.references`, `.disclaimer`, `.payment`, `.payment-qr svg`, `.payment__details`, `.payment__iban`, `.payment .warning`, `.samples`, `.samples a[href=…]`, `.setup-note`, `.upload-status`; form field `letter_upload[letter]`, `letter_upload[easyLanguage]`; buttons found by label ("Brief erklären" / "Explain letter").
- The upload-progress controller (busy state, double-submit guard, `pageshow` reset) must keep working with the dropzone.
- Accessibility: text contrast at least 4.5:1 (WCAG AA) on the dark background, visible focus, warnings never by colour alone (icon plus text, as today).
- Synthetic data only, also in screenshots.
- Shared files touched: root `README.md` (name, screenshot later) — goes through PR review by the pii-shield owner.

## The dropzone

- A `<label>`/region wrapping the real `<input type="file">` (visually hidden but focusable), so click, keyboard (Enter/Space on the button) and screen readers use the native input.
- Stimulus controller: highlight on dragover, on drop assign `event.dataTransfer.files` to the input, show the chosen file name and size, client-side check of type (PDF, PNG, JPEG, TIFF) and size (10 MB) with a message; server-side validation stays the source of truth.
- After a file is chosen: either submit right away or show the file and an "Explain letter" button. Builder's call; the owner saw a "choose file" button in the mockup, so showing the chosen file plus the submit button is the safe default.
- While the request runs, the existing progress state, ideally showing the four steps (the request is synchronous, so the steps are an indication, not real progress; do not pretend exact timing).

## Left to the builder

- Font: a self-hosted variable sans (e.g. Inter, SIL OFL) or the system font stack; monospace for tokens. Licence file next to the font as today.
- Exact spacing, radii, shadows; contrast tuning of muted text and of the gradient on buttons (dark text on the gradient, as in the mockup).
- Icons: inline SVG (Lucide-style, as today).
- Result page layout in the new look; keep its information order (sender, subject, warnings, deadline card, amount, summary, actions, payment, questions, legal references, disclaimer, again button).
- English copy for the new sections (the mockup is German; translate in the same tone).
- Where the new section texts live in the translation files (`letter.landing.*` or similar).
- Favicon and an Open Graph image are nice to have.
