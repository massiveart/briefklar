# Plan

Team: André (pii-shield, MCP), Cornelius (briefklar app).
Window: Mon 09.11.2026 to Sun 22.11.2026, 18:00 CET. Internal submission target: **Sun 22.11., 12:00** (6 h buffer).

## Scope

| # | Workstream | Owner | Status |
|---|---|---|---|
| A | `pii-shield`: reversible DACH PII redaction as Symfony AI processors | André | must |
| B | `app`: upload, local OCR, explanation agent, UI | Cornelius | must |
| C | MCP server: `explain_letter`, `redact_text` via `mcp/sdk` | André | must (small) |
| D | `ris-client`: resolve legal citations from the letter via RIS | both | stretch, go/no-go Wed 18.11. |

**Why C is "must":** the hackathon is judged by the MCP maintainers. Without any MCP part we only use half of the stack. C is deliberately small (about one day) and reuses A and B.

## Milestones

| Date | Milestone | Exit criterion |
|---|---|---|
| Mon 09.11. | M0 Setup | Repo public-ready, CI green on skeleton, `PiiShieldInterface` agreed, `NullPiiShield` usable by B |
| Fri 13.11. | M1 Components | A: regex + checksum detectors for all `EntityType`s, vault, restore; tests green against fixtures. B: upload -> OCR -> agent -> structured answer working with `NullPiiShield` |
| Sun 15.11. | M2 End-to-end | Real `PiiShield` wired into app via Symfony AI processors; fixture letter 01 runs end-to-end; no PII in provider request (verified by test with mocked platform) |
| Wed 18.11. | M3 Feature complete | C done (MCP Inspector demo works). NER-based name/address detection via Hugging Face. **Go/no-go for D** |
| Fri 20.11. | M4 Freeze | No new features. Only bugs, README, polish |
| Sat 21.11. | M5 Ship prep | Stranger test: someone outside the team gets from `git clone` to running demo in 5 min. Video recorded |
| Sun 22.11. 12:00 | Submit | Repo public, video uploaded, submission done |

## Go/no-go rule for D (Wed 18.11.)

Go only if M2 and C are done and the end-to-end demo has no known blocker. Otherwise D moves to "after the hackathon" in the README roadmap. A half-finished D hurts the "it actually works" criterion more than it helps.

## Daily sync

15 min, async in a shared channel is fine. Three questions: what's merged, what's blocked, does the contract need to change.

## Risks

| Risk | Mitigation |
|---|---|
| Pre-release API changes in Symfony AI / mcp/sdk | Pin exact versions on day 0, upgrade only deliberately. Log breakages in `docs/upstream-issues.md` |
| LLM mangles placeholders, restore fails | Placeholder format robust against reformatting; OutputProcessor validates every placeholder; tests with mangled outputs |
| Over-redaction kills the explanation (amounts, deadlines removed) | Amounts and non-birth dates are explicitly *not* PII; fixture expectations encode this |
| OCR quality on photos | Demo with scanned PDFs first; photos are a bonus |
| 5-minute setup fails on jury machines | Docker-only path, no host PHP required; stranger test on Sat 21.11. |
