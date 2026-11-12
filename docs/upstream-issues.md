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

## HTTP transport fails with a 500 until a PSR-17 implementation is installed
- Package / version: `symfony/mcp-bundle` 1.0.0-BETA1 with `mcp/sdk` 0.9.0
- Reproduction: in a Symfony 8.1 app without any PSR-7/PSR-17 package, `composer require symfony/mcp-bundle`, keep `transports.http: true`, POST an `initialize` request to `/mcp`.
- Expected: the bundle requires (or the recipe installs) a PSR-17 implementation, or the docs say to add one.
- Actual: `Http\Discovery\Exception\NotFoundException: No PSR-17 server request factory found` from `McpController` via `PsrHttpFactory::createRequest()`. The Flex recipe of `php-http/discovery` adds `config/packages/http_discovery.yaml` pointing to `Http\Discovery\Psr17Factory`, and the bundle requires `php-http/discovery`, but nothing installs an implementation. STDIO works, so the problem shows up only on the first HTTP request.
- Workaround: `composer require nyholm/psr7`.
- Filed: not yet
