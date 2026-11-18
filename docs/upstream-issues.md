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
- Workaround: `clarifile/pii-shield` keys a `WeakMap` by the `MessageBag` instance the input processor sets; `Output::getMessageBag()` returns that same instance. Works, but relies on object identity, which is not part of the documented contract.
- Expected: a per-call context object (or attributes bag) on `Input`/`Output` that is never sent to the platform.
- Filed: no. On re-check an equivalent exists: `MessageBag` uses `MetadataAwareTrait`, its metadata is not part of the provider payload (the contract normalizers ignore it) and survives `with()`/`merge()`, because they clone it. We keep the `WeakMap` on purpose: bag metadata is serialized by `Serializer\MessageBagNormalizer` (message stores), and the redaction mapping holds the original personal data, which must not be persisted. Object identity also holds on upstream `main` (checked 17.11.2026): the runner works on copies and `Output` receives the bag the input processors set.

## Restoring structured output needs reflection on readonly DTOs
- Package / version: `symfony/ai-platform` 1.0.0-BETA1
- Reproduction: an output processor wants to post-process string fields of an `ObjectResult` whose content is a readonly DTO (structured output).
- Workaround: copy the object with `ReflectionClass::newInstanceWithoutConstructor()` and initialise properties from the declaring class scope.
- Filed: no. On re-check this is not an upstream gap: for structured output, `StructuredOutput\ResultConverter` passes the raw JSON to `ObjectResult`, so `getText()` returns it. An output processor can restore the JSON and deserialize it again instead of using reflection. Our earlier note ("`getText()` is often null") holds only for other converters (e.g. Whisper, Mistral OCR), which are not structured output.

## Docs: processors run once per call, not per tool round
- Package / version: `symfony/ai-agent` 1.0.0-BETA1 (same on `main` at 999d5fbeea)
- Reproduction: an agent with an input processor and a tool. `Agent::call()` runs the input processors once; `Execution\Runner` appends assistant messages and tool results with `MessageBag::with()` and calls the platform again per tool round, without the input processors.
- Expected: the docs say so. `agent.rst` says input processors run "before handing over the MessageBag … to the LLM", which reads like "before every model request".
- Actual: undocumented. For a redaction processor, personal data returned by a tool reaches the provider unredacted, without an error.
- Filed: docs PR https://github.com/symfony/ai/pull/2978

## HTTP transport fails with a 500 until a PSR-17 implementation is installed
- Package / version: `symfony/mcp-bundle` 1.0.0-BETA1 with `mcp/sdk` 0.9.0
- Reproduction: in a Symfony 8.1 app without any PSR-7/PSR-17 package, `composer require symfony/mcp-bundle`, keep `transports.http: true`, POST an `initialize` request to `/mcp`.
- Expected: the bundle requires (or the recipe installs) a PSR-17 implementation, or the docs say to add one.
- Actual: `Http\Discovery\Exception\NotFoundException: No PSR-17 server request factory found` from `McpController` via `PsrHttpFactory::createRequest()`. The Flex recipe of `php-http/discovery` adds `config/packages/http_discovery.yaml` pointing to `Http\Discovery\Psr17Factory`, and the bundle requires `php-http/discovery`, but nothing installs an implementation. STDIO works, so the problem shows up only on the first HTTP request.
- Workaround: `composer require nyholm/psr7`.
- Filed: no, fixed upstream in symfony/ai 80854de613 ("[MCP Bundle] Let php-http/discovery install a PSR-17 implementation", 27.10.2026) after 1.0.0-BETA1. Drop `nyholm/psr7` when we upgrade past BETA1.
