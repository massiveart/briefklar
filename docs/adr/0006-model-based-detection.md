# ADR 0006: Model-based detection through Symfony AI Platform

## Status
Accepted (10.11.2026). Refines the rule "no PII to any LLM provider before redaction" in `AGENTS.md`.

## Context
Regex and checksum detectors cover DACH formats well (SVNR, IBAN, tax numbers) but miss everything without a fixed format: names without salutation, foreign ID numbers, health information, addresses in other countries. For a Symfony AI project the obvious answer is a model, and the model must be the integrator's choice: a local model, a model on their own GPU server (e.g. a Hetzner GEX44 running Ollama or vLLM) or a public LLM.

Detection is the one step that has to see the unredacted text. Where that model runs decides whether personal data leaves the integrator's infrastructure.

## Decision
- `ModelDetector` (in `Bridge/SymfonyAi`) implements `DetectorInterface` on top of any `PlatformInterface` and model name. It asks for JSON (type + exact value), grounds every value in the original text (whitespace and commas may differ) and drops values the model invented. Reasoning blocks (`<think>`) are ignored. An unusable answer throws `DetectionFailedException`: detection fails closed, it never silently continues with fewer findings.
- `ModelLocation` (`Local`, `OnPremise`, `External`) is part of the configuration. **`External` requires `acknowledgeExternalTransfer: true`**, otherwise construction fails. The demo shows a warning and requires a checkbox per session.
- The model detector runs alongside the regex detectors in the same `PiiShield`; overlap resolution prefers longer spans and, on equal length, checksum-validated findings.
- `EntityType` gains `ID_NUMBER`, `CREDIT_CARD`, `IP_ADDRESS`, `HEALTH` and `OTHER`, so the model can report anything it recognises. Additive change; the app does not match on `EntityType`.
- Providers come from the official Symfony AI bridges (`symfony/ai-*-platform` 1.0.0-BETA1): Ollama, Generic (any OpenAI-compatible server such as vLLM), Mistral, OpenAI, Anthropic. They are dev/suggested dependencies of the package; the integrator installs the one they use.
- The prompt asks the model not to report amounts, non-birth dates, authority names and legal citations, matching ADR 0003's "must survive" list.

## Rejected
- **Hugging Face token classification only** (original plan for deliverable 7): limited to the entity sets of the chosen NER model and to the Hugging Face API; a chat model via the platform abstraction covers it and more, and runs locally.
- **Allowing external providers without acknowledgement:** would contradict the purpose of the package and GDPR (data processing agreement, possibly third-country transfer) without anybody noticing.
- **Native structured output / JSON mode:** not every model supports it (e.g. Ollama MLX models answer HTTP 501); prompting for JSON and parsing leniently works across providers.

## Consequences
- Detection quality depends on the chosen model; the regex detectors stay as a deterministic floor.
- Latency: seconds instead of milliseconds (≈ 11–17 s for a 27B model on a MacBook in our tests). The demo debounces model calls and runs PHP's built-in server with several workers.
- Tests use `MockModelClient`; a real end-to-end run against a local Ollama is documented in the package README.
