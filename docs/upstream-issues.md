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

## `#[IsGrantedTool]` listener breaks the container without SecurityBundle
- Package / version: `symfony/ai-bundle` 1.0.0-BETA1 (same on `main` at ef3728f289, 19.11.2026)
- Reproduction: a Symfony 8.1 app with `symfony/ai-bundle` and `symfony/form`, no SecurityBundle. `composer require symfony/security-csrf` (for form CSRF protection; it requires `symfony/security-core`).
- Expected: the container compiles; the listener for `#[IsGrantedTool]` is only kept when `security.authorization_checker` exists.
- Actual: `The service "ai.security.is_granted_attribute_listener" has a dependency on a non-existent service "security.authorization_checker".` `AiBundle::loadExtension()` keeps the listener as soon as `AuthorizationCheckerInterface` (security-core) is installed, but the service comes from SecurityBundle.
- Workaround: `App\Kernel::process()` removes the listener when SecurityBundle is not installed.
- Filed: not yet.

## Stress test of 1.0.0-BETA1 and mcp/sdk (19.11.2026)
Targeted run against `symfony/ai` 1.0.0-BETA1 / `main` ef3728f and `mcp/sdk` 0.9.0 / v0.10.0 / `main` 3d2e831. Every finding was reproduced on `main` with its own minimal repro and checked against all existing issues and PRs before filing. None of them affects Clarifile directly.

| Finding | Package | Filed |
|---|---|---|
| `Toolbox` accepts duplicate tool names and calls the wrong tool | `symfony/ai-agent` | https://github.com/symfony/ai/issues/3003, fix PR https://github.com/symfony/ai/pull/3017 |
| Typed `options` of an agent model lose their string type (`'007'` → `7`) | `symfony/ai-bundle` | https://github.com/symfony/ai/issues/3004 |
| `#[Schema(enum: [...])]` cannot narrow a backed-enum parameter | `symfony/ai-platform` | https://github.com/symfony/ai/issues/3005, fix PR https://github.com/symfony/ai/pull/3015 |
| Explicit `null` for string options becomes `""`; reference config fails to compile | `symfony/mcp-bundle` | https://github.com/symfony/ai/issues/3006 |
| Self-referencing classes exhaust memory in schema generation; `mixed`/`iterable` schemas | `symfony/ai-platform` | comment on https://github.com/symfony/ai/pull/2915 (fixes them as a side effect) |
| `#[Schema(exclusiveMinimum: true)]` is invalid under JSON Schema 2020-12 | `mcp/sdk` | https://github.com/modelcontextprotocol/php-sdk/issues/754 |
| Error response to a repeated `initialize` has no `id` | `mcp/sdk` | https://github.com/modelcontextprotocol/php-sdk/issues/755, fix PR https://github.com/modelcontextprotocol/php-sdk/pull/758 |
| A `float $x = INF` default makes `tools/list` fail for all tools | `mcp/sdk` | https://github.com/modelcontextprotocol/php-sdk/issues/756, fix PR https://github.com/modelcontextprotocol/php-sdk/pull/759 |
| Class-typed params and enum arrays still fail at call time with the new schema generator | `mcp/sdk` | comment on https://github.com/modelcontextprotocol/php-sdk/pull/740 |

Fixed on `main` after BETA1, relevant while we stay on it: a tool returning `void`/`null` crashes the agent run (`Message::ofToolCall()` TypeError, symfony/ai #2896). Tools must return a string.

Second round (20.11.2026), focused on what Clarifile uses: the Ollama, Hugging Face and Generic bridges and the MCP client. Same verification as above.

| Finding | Package | Filed |
|---|---|---|
| `StringTemplateRenderer` expands placeholders inside substituted values | `symfony/ai-platform` | fix PR https://github.com/symfony/ai/pull/3016 |
| Backed enums in tool results are serialized as `{"name","value"}` | `symfony/ai-agent` | fix PR https://github.com/symfony/ai/pull/3018 |
| Explicit nested Ollama `options` are sent twice | `symfony/ai-ollama-platform` | fix PR https://github.com/symfony/ai/pull/3019 |
| Generic/Hugging Face errors with a string `error` or a 402/403/404/422 status lose their message | `symfony/ai-generic-platform` | https://github.com/symfony/ai/issues/3020 |
| Ollama `image` capability breaks `ModelCatalog::getModels()` | `symfony/ai-ollama-platform` | https://github.com/symfony/ai/issues/3021 |
| Doubled quotes in the mcp-bundle registry pattern error | `symfony/mcp-bundle` | comment on https://github.com/symfony/ai/issues/3002 |
| Client answers a server `ping` with -32601 | `mcp/sdk` | fix PR https://github.com/modelcontextprotocol/php-sdk/pull/761 |
| Client drops server requests with an unknown method or invalid params | `mcp/sdk` | fix PR https://github.com/modelcontextprotocol/php-sdk/pull/762 |
| Listening-stream client tests hang on macOS (8 KB socket buffer) | `mcp/sdk` | fix PR https://github.com/modelcontextprotocol/php-sdk/pull/760 |
