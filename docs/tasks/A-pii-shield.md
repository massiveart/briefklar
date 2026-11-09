# Task A: pii-shield (owner: André)

## Goal
A standalone Composer package that redacts DACH personal data reversibly and plugs into Symfony AI agents as input/output processors.

## Deliverables (in order)
1. **Validators** (`src/Validator/`), test-first with valid and invalid samples:
   - `AtSvnrValidator` (check digit, weights 3,7,9,0,5,8,4,2,1,6, sum mod 11, result 10 is invalid)
   - `IbanValidator` (ISO 13616, mod 97)
   - `DeTaxIdValidator` (Steuer-ID check digit)
2. **Regex detectors** (`src/Detector/`) for SVNR, IBAN, TAX_NUMBER, EMAIL, PHONE, BIRTH_DATE, FILE_NUMBER. Each detector returns `Entity` objects with offsets. Detectors use validators to drop false positives.
3. **`PiiShield`** implementing `PiiShieldInterface`: runs detectors, resolves overlapping spans (longest/highest-confidence wins), assigns consistent placeholders, returns `RedactionResult`.
4. **Restore** with tolerance for whitespace/case changes around placeholders; report unknown and unresolved placeholders in `RestoreResult`.
5. **Symfony AI bridge** (`src/Bridge/SymfonyAi/`):
   - `PiiShieldInputProcessor` (implements `Symfony\AI\Agent\InputProcessorInterface`): redacts all user and system message text in the `MessageBag`, stores the `RedactionResult` for the output side.
   - `PiiShieldOutputProcessor` (implements `Symfony\AI\Agent\OutputProcessorInterface`): restores text results and structured output string fields.
   - Verify the exact interfaces and how state can be passed from input to output in the installed `symfony/ai-agent` version before implementing. If there is no clean way, document it in `docs/upstream-issues.md`.
6. **Bundle** (`src/Bridge/Symfony/PiiShieldBundle`) with config: enabled entity types, which agents to apply to, NER on/off.
7. **NER detector** for PERSON and ADDRESS via Symfony AI Platform (Hugging Face token classification). Check which German-capable NER/PII model is available on Hugging Face Inference Providers; make the model configurable. Must be optional; regex-only mode must work offline.

## Tests that must exist
- Every fixture in `fixtures/letters/*.txt` is redacted so that every span in its `.expected.json` is covered, and every `must_survive` string is still present.
- A test with a mocked platform asserting that no original PII string appears in the request sent to the platform.
- Restore round-trip: `restore(redact(x)) === x` for all fixtures.

## Out of scope
Images, PDFs, OCR (that's task B). Persisting the vault. Any UI.

## Done when
Milestone M2 in `docs/PLAN.md`.
