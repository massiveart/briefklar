# briefklar/pii-shield

Reversible PII redaction for [Symfony AI](https://ai.symfony.com), focused on the DACH region.

- Checksum-validated recognizers: Austrian social security number (SVNR), IBAN, German tax ID
- Context-aware: birth dates are redacted, deadlines and amounts are not
- Consistent typed placeholders (`⟦PERSON_1⟧`) that models can reason about
- Plugs into any Symfony AI agent as input/output processor, restores values in the answer
- Optional NER for names and addresses via Hugging Face, regex-only mode works offline

Status: work in progress (Symfony AI & MCP Hackathon 2026). See `../../docs/tasks/A-pii-shield.md`.
