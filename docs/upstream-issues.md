# Upstream issues

Bugs and rough edges found in Symfony AI and `mcp/sdk` during the hackathon. Each entry: version, minimal reproduction, expected vs. actual, link once filed.

- Symfony AI: https://github.com/symfony/ai/issues
- PHP MCP SDK: https://github.com/modelcontextprotocol/php-sdk/issues

<!-- template
## <short title>
- Package / version:
- Reproduction:
- Expected:
- Actual:
- Filed:
-->

## No per-call context to pass state from input to output processors
- Package / version: `symfony/ai-agent` 1.0.0-BETA1
- Reproduction: an input processor redacts the `MessageBag` and needs the redaction mapping in the output processor of the same `Agent::call()`. `Input` and `Output` share only model, `MessageBag` and options. Options are forwarded to the platform (and may end up in the provider request), so they cannot carry sensitive state.
- Workaround: `briefklar/pii-shield` keys a `WeakMap` by the `MessageBag` instance the input processor sets; `Output::getMessageBag()` returns that same instance. Works, but relies on object identity, which is not part of the documented contract.
- Expected: a per-call context object (or attributes bag) on `Input`/`Output` that is never sent to the platform.
- Filed: no. On re-check an equivalent exists: `MessageBag` uses `MetadataAwareTrait`, its metadata is not part of the provider payload (the contract normalizers ignore it) and survives `with()`/`merge()`, because they clone it. We keep the `WeakMap` on purpose: bag metadata is serialized by `Serializer\MessageBagNormalizer` (message stores), and the redaction mapping holds the original personal data, which must not be persisted. Object identity also holds on upstream `main` (checked 17.11.2026): the runner works on copies and `Output` receives the bag the input processors set.

## Restoring structured output needs reflection on readonly DTOs
- Package / version: `symfony/ai-platform` 1.0.0-BETA1
- Reproduction: an output processor wants to post-process string fields of an `ObjectResult` whose content is a readonly DTO (structured output).
- Workaround: copy the object with `ReflectionClass::newInstanceWithoutConstructor()` and initialise properties from the declaring class scope.
- Filed: no. On re-check this is not an upstream gap: for structured output, `StructuredOutput\ResultConverter` passes the raw JSON to `ObjectResult`, so `getText()` returns it. An output processor can restore the JSON and deserialize it again instead of using reflection. Our earlier note ("`getText()` is often null") holds only for other converters (e.g. Whisper, Mistral OCR), which are not structured output.
