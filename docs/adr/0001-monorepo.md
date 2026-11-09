# ADR 0001: Monorepo with independent packages

## Status
Accepted (09.11.2026)

## Context
Two people, 13 days, one demo, one README, one video. Judges must be able to clone and run within 5 minutes. At the same time, `pii-shield` should be reusable by anyone using Symfony AI, which means it must be installable on its own.

## Decision
One repository. Inside it, `packages/*` are self-contained Composer packages (own `composer.json`, tests, README, no dependency on `app/`). The app consumes them via Composer path repositories.

After the hackathon, packages are split into read-only repositories with `splitsh-lite` (the same approach Symfony uses for its components) and published on Packagist.

## Rejected
- **Separate repositories now:** triple CI, triple README, cross-repo changes during the integration phase. Too much overhead for 13 days.
- **Single app without packages:** fastest, but the reusable part (the actual contribution to the ecosystem) would be buried in a demo app.

## Consequences
Discipline needed: no imports from `app/` into `packages/`. Enforced by CI running each package's tests in isolation.
