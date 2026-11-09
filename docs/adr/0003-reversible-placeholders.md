# ADR 0003: Reversible, consistent placeholders

## Status
Accepted (09.11.2026)

## Context
Masking with `***` destroys meaning ("*** owes *** to ***"). The explanation needs to refer back to "you", "the authority", "your account".

## Decision
Typed, numbered placeholders (`⟦PERSON_1⟧`), consistent per document. The mapping stays in memory for one request and is used by the OutputProcessor to restore values. The OutputProcessor reports placeholders it cannot resolve and placeholders the model invented.

## Consequences
Restore must be robust: tests cover placeholders with changed whitespace, case and markdown around them.
