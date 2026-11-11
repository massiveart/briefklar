# Fixture letters

**All letters are synthetic.** Names, addresses and numbers are invented. SVNRs and IBANs have valid check digits on purpose, so validators can be tested; they do not belong to real persons. The IBANs are documentation examples.

Each `NN-name.txt` has a `NN-name.expected.json`:
- `pii`: spans that must be redacted (type + exact value)
- `must_survive`: strings that must still be present after redaction (amounts, deadlines, authorities, legal citations)
- `negative`: strings that look like PII but must *not* be redacted, or invalid numbers that must be rejected by validators
- `detection` (optional): `"model"` for letters only model-based detection can handle (e.g. a name without salutation); the regex acceptance tests skip them

Add new fixtures here first, then tests. Never add real letters.
