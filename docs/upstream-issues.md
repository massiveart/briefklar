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
- Actual: no such API.
- Filed: not yet

## Restoring structured output needs reflection on readonly DTOs
- Package / version: `symfony/ai-platform` 1.0.0-BETA1
- Reproduction: an output processor wants to post-process string fields of an `ObjectResult` whose content is a readonly DTO (structured output).
- Workaround: copy the object with `ReflectionClass::newInstanceWithoutConstructor()` and initialise properties from the declaring class scope.
- Expected: a hook before deserialization (raw JSON) for output processors, or access to the raw structured text. `ObjectResult::getText()` is often null.
- Actual: only the deserialized object is available.
- Filed: not yet
