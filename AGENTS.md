# AGENTS.md

Instructions for coding agents (Claude Code, Cursor, others) working in this repository.

## Context

Hackathon project for the Symfony AI & MCP Hackathon. Hard deadline: **22.11.2026, 18:00 CET** (public repo + 90 s video).
Judging: creative idea, working demo, what Symfony AI and MCP unlocked, craft (idiomatic Symfony, typed, tested, README to running demo in 5 min).
Read `docs/PLAN.md` and `docs/ARCHITECTURE.md` before starting. Your concrete work package is in `docs/tasks/`.

## Ground rules

- **Symfony AI and `mcp/sdk` are pre-release.** Before using any API, check the installed source in `vendor/` rather than relying on memory. If something is broken or undocumented, write a minimal reproduction to `docs/upstream-issues.md` (we file them upstream; that counts for the hackathon).
- **Never commit real personal data.** All fixtures are synthetic. Never add real letters, names or numbers, not even in tests or screenshots.
- **No PII to any LLM provider before redaction.** OCR runs locally. Do not send images to a vision model; images contain PII. The only exception is model-based *detection* (ADR 0006): it runs on a local or on-premise model by default; an external provider needs an explicit acknowledgement in configuration.
- **Packages stay independent.** `packages/*` must not depend on `app/`. `pii-shield` core must not depend on Symfony AI; the Symfony AI integration lives in `src/Bridge/SymfonyAi`.
- **Contract first.** `packages/pii-shield/src/PiiShieldInterface.php` is the shared contract between workstreams. Changing it needs agreement of both owners.

## Coordination between workstreams

Handovers between pii-shield (owner `andre-schade`) and the app (owner `c00n84`) are GitHub issues, not files:

- **At session start**, check what is assigned to you: `gh issue list --assignee @me --label handover --state open`.
- **To hand something over**, open an issue from the "Handover" template (`gh issue create --template handover.md`), add the label `for:pii-shield` or `for:app` and assign the owner. Context, findings, wanted, acceptance, not affected.
- **Questions and answers** go into issue comments; the owner closes the issue with the PR that fixes it (`Fixes #n`) and says in a comment which version or commit to pull.
- The repository is public: synthetic data only, also in issues, logs and screenshots.

## Code style

- PHP 8.4, `declare(strict_types=1)`, `final` by default, readonly value objects.
- Symfony coding standard (php-cs-fixer `@Symfony`), PHPStan level 8.
- Tests with PHPUnit. Detectors and validators: test-first, table-driven with valid and invalid samples.
- English for code, docs and commits. German only in fixtures and user-facing UI strings (translated via Symfony Translation).
- Conventional commits (`feat(pii-shield): ...`).

## Commands

```bash
make install     # composer install for all packages and the app
make test        # all test suites
make qa          # cs-check + phpstan
make up          # docker compose up
```

## Definition of done (per task)

- Tests green, `make qa` green.
- README of the touched package updated.
- No TODO left in code that the demo depends on.
