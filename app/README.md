# briefklar app

Symfony application: upload, local OCR, explanation agent, UI, MCP server.

## Stack

| | Version | Why |
|---|---|---|
| Symfony | 8.1 (current stable) | `symfony/ai-bundle` 1.0.0-BETA1 allows `^7.3\|^8.0`; 8.1 needs PHP >= 8.4.1 |
| PHP | 8.4 | see `../Dockerfile` |
| Symfony AI | 1.0.0-BETA1, pinned exactly | see `../docs/adr/0004-pin-pre-release-dependencies.md` |
| Platform bridges | `ai-hugging-face-platform`, `ai-anthropic-platform`, `ai-ollama-platform`, `ai-generic-platform` (transitive, pinned too) | provider switch via env |
| UI | Twig, AssetMapper, Symfony UX Turbo | no Node build step |

## Packages

`briefklar/pii-shield` is required via a Composer path repository (`../packages/pii-shield`, symlinked).

The app depends only on `Briefklar\PiiShield\PiiShieldInterface`. Which implementation is used is decided by one alias in `config/services.yaml`:

```yaml
Briefklar\PiiShield\PiiShieldInterface: '@Briefklar\PiiShield\NullPiiShield'
```

Until task A ships, this is `NullPiiShield` (pass-through). Switching to the real shield is a config change only.

## Development

No PHP on the host required. PHP 8.4, Composer and Tesseract run in the app container; the Makefile in the repository root wraps everything:

```bash
make build                            # build the app image once
make install                          # composer install for packages and app
make test                             # all PHPUnit suites
make qa                               # php-cs-fixer + PHPStan level 8
make console c="debug:container"      # bin/console in the container
make composer c="require foo/bar"     # composer in app/
make sh                               # interactive shell in the container
```

With a local PHP 8.4, append `DOCKER=0` to any target. The Composer cache lives in the `composer-cache` Docker volume.
