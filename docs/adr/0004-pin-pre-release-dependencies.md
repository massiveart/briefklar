# ADR 0004: Pin pre-release Symfony AI and MCP dependencies exactly

## Status
Accepted (09.11.2026)

## Context
Symfony AI and the PHP MCP SDK are pre-release. Minor releases have broken APIs before, and a surprise upgrade during the hackathon could break the demo shortly before the deadline (22.11.2026). `docs/PLAN.md` requires pinning exact versions on day 0.

Latest releases on Packagist as of 09.11.2026:

| Package | Version | Released |
|---|---|---|
| `symfony/ai-platform` | 1.0.0-BETA1 | 26.10.2026 |
| `symfony/ai-agent` | 1.0.0-BETA1 | 26.10.2026 |
| `symfony/ai-bundle` | 1.0.0-BETA1 | 26.10.2026 |
| `symfony/mcp-bundle` | 1.0.0-BETA1 | 22.10.2026 |
| `mcp/sdk` | 0.9.0 | 18.10.2026 |

The set is consistent: `symfony/ai-bundle` 1.0.0-BETA1 requires `symfony/ai-platform ^1.0`, `symfony/mcp-bundle` 1.0.0-BETA1 requires `mcp/sdk ^0.9`. All require PHP >= 8.2 (we use 8.4).

## Decision
Use exact constraints (no `^`, no `~`) for the versions above:

- `app/composer.json`: `symfony/ai-bundle`, `symfony/ai-platform`, `symfony/ai-agent`, `symfony/mcp-bundle`, `mcp/sdk`.
- `packages/pii-shield`: `symfony/ai-agent` and `symfony/ai-platform` only in `require-dev` (for bridge tests); they stay in `suggest` for consumers.

Pins are added when the respective code is introduced (bridge in task A, app setup in task B), not before. Upgrades are deliberate, in a dedicated PR, with the full test suite green.

## Rejected
- **0.15.0 line:** older API that 1.0 is about to replace; the judges are the maintainers and will look at the current API. Stays the fallback if BETA1 is blocked.
- **Caret constraints (`^1.0@beta`):** would pull BETA2 or RC automatically during the hackathon.
- **`dev-main`:** no reproducibility.

## Consequences
Breakages in BETA1 are worked around locally and reported in `docs/upstream-issues.md`. If BETA1 blocks the demo, all Symfony AI packages move to 0.15.0 together, never mixed.
