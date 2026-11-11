<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Detector\EmailDetector;
use Briefklar\PiiShield\Detector\PhoneDetector;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\DetectionFailedException;
use Briefklar\PiiShield\Exception\LearningFailedException;
use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\PiiMasker;
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\Vault\VaultInterface;

/**
 * Versioned HTTP API (/v1) for apps that use pii-shield as a service. Documented in docs/openapi.yaml.
 *
 * The placeholder mapping never leaves the service: /v1/redact returns placeholders and a vault id,
 * /v1/restore resolves them. Which model detects is server configuration, never request input.
 *
 * @phpstan-type Response array{status: int, body: array<string, mixed>|null}
 */
final class ServiceApi
{
    private const int MAX_TEXT_LENGTH = 100_000;

    /**
     * @param (\Closure(): ModelDetector)|null $modelDetector null if no model is configured
     * @param string|null                      $token         bearer token; null allows requests from localhost only
     * @param bool                             $requireToken  service mode (Docker): no localhost fallback, refuse without token
     */
    public function __construct(
        private readonly VaultInterface $vault,
        private readonly EntityLearner $learner,
        private readonly ?\Closure $modelDetector,
        private readonly ?string $token,
        private readonly int $vaultTtl = 900,
        private readonly bool $requireToken = false,
    ) {
    }

    /**
     * @param array<string, mixed>|null $body decoded JSON body
     *
     * @return Response
     */
    public function handle(string $method, string $path, ?array $body, ?string $authorization, string $remoteAddress): array
    {
        if ('/v1/health' === $path) {
            return 'GET' === $method ? self::ok(['status' => 'ok', 'modelDetection' => null !== $this->modelDetector]) : self::error(405, 'Use GET.');
        }
        if ($this->requireToken && (null === $this->token || '' === $this->token)) {
            return self::error(503, 'Set PII_SERVICE_TOKEN to use this service.');
        }
        if (!$this->authorized($authorization, $remoteAddress)) {
            return self::error(401, 'Missing or invalid bearer token.');
        }

        $routes = ['/v1/redact' => 'redact', '/v1/restore' => 'restore', '/v1/mask' => 'mask', '/v1/learn' => 'learn', '/v1/entities' => 'entities'];
        if (isset($routes[$path])) {
            $allowed = 'entities' === $routes[$path] ? 'GET' : 'POST';

            return $method === $allowed ? $this->operation($routes[$path], $body ?? []) : self::error(405, \sprintf('Use %s.', $allowed));
        }
        if (1 === preg_match('~^/v1/entities/([A-Z][A-Z0-9_]{1,39})$~', $path, $m)) {
            return 'DELETE' === $method ? $this->forgetType($m[1]) : self::error(405, 'Use DELETE.');
        }
        if (1 === preg_match('~^/v1/vault/([0-9a-f]{32})$~', $path, $m)) {
            if ('DELETE' !== $method) {
                return self::error(405, 'Use DELETE.');
            }
            $this->vault->delete($m[1]);

            return ['status' => 204, 'body' => null];
        }

        return self::error(404, 'Not found.');
    }

    /**
     * One operation without routing or authentication (the caller has authenticated), shared by
     * the HTTP API and the MCP tools.
     *
     * @param 'redact'|'restore'|'mask'|'learn'|'entities' $operation
     * @param array<string, mixed>                         $body
     *
     * @return Response
     */
    public function operation(string $operation, array $body): array
    {
        try {
            return match ($operation) {
                'redact' => $this->redact($body),
                'restore' => $this->restore($body),
                'mask' => $this->mask($body),
                'learn' => $this->learn($body),
                'entities' => self::ok(['types' => array_map(static fn ($t): array => $t->toArray(), $this->learner->types())]),
            };
        } catch (\InvalidArgumentException $e) {
            return self::error($e instanceof LearningFailedException ? 422 : 400, $e->getMessage());
        } catch (DetectionFailedException) {
            // Details (model name, URLs, provider errors) stay on the server.
            return self::error(502, 'Model-based detection failed.');
        } catch (\RuntimeException) {
            return self::error(500, 'Detection failed.');
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Response
     */
    private function redact(array $body): array
    {
        $redaction = (new PiiShield($this->detectors($body), $this->vault, $this->vaultTtl, self::strings($body, 'allowlist')))->redact(self::text($body));

        $placeholders = [];
        foreach (array_keys($redaction->vault) as $placeholder) {
            $placeholders[] = ['placeholder' => $placeholder, 'type' => (string) preg_replace('/^⟦(.+)_\d+⟧$/u', '$1', $placeholder)];
        }

        return self::ok([
            'text' => $redaction->redactedText,
            'vaultId' => $redaction->vaultId,
            'expiresIn' => $this->vaultTtl,
            'placeholders' => $placeholders,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Response
     */
    private function restore(array $body): array
    {
        $vaultId = \is_string($body['vaultId'] ?? null) ? $body['vaultId'] : '';
        try {
            $restored = (new PiiShield([], $this->vault))->restoreFromVault(self::text($body), $vaultId);
        } catch (VaultException) {
            return self::error(404, 'Unknown or expired vault id. Redact the text again.');
        }

        return self::ok([
            'text' => $restored->text,
            'unknownPlaceholders' => $restored->unknownPlaceholders,
            'unusedPlaceholders' => $restored->unusedPlaceholders,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Response
     */
    private function mask(array $body): array
    {
        return self::ok(['text' => (new PiiMasker($this->detectors($body), allowlist: self::strings($body, 'allowlist')))->mask(self::text($body))]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Response
     */
    private function learn(array $body): array
    {
        $args = [
            \is_string($body['name'] ?? null) ? $body['name'] : '',
            \is_string($body['description'] ?? null) ? $body['description'] : '',
            self::strings($body, 'examples'),
            self::strings($body, 'counterExamples'),
            \is_string($body['pattern'] ?? null) ? $body['pattern'] : null,
        ];
        $dryRun = true === ($body['dryRun'] ?? false);
        $result = $dryRun ? $this->learner->preview(...$args) : $this->learner->learn(...$args);

        return ['status' => $dryRun ? 200 : 201, 'body' => ['type' => $result->type->toArray(), 'note' => $result->note, 'saved' => !$dryRun]];
    }

    /**
     * @return Response
     */
    private function forgetType(string $name): array
    {
        if (null === array_find($this->learner->types(), static fn ($t): bool => $t->name === $name)) {
            return self::error(404, \sprintf('No learned type "%s".', $name));
        }
        $this->learner->forget($name);

        return ['status' => 204, 'body' => null];
    }

    /**
     * Built-in patterns, learned types and, on request, the configured model.
     *
     * @param array<string, mixed> $body
     *
     * @return list<DetectorInterface>
     */
    private function detectors(array $body): array
    {
        $detection = $body['detection'] ?? 'regex';
        if (!\in_array($detection, ['regex', 'model', 'both'], true)) {
            throw new \InvalidArgumentException('"detection" must be "regex", "model" or "both".');
        }

        $detectors = [$this->learner->detector()];
        if ('model' !== $detection) {
            $keepAuthority = false !== ($body['keepAuthorityContacts'] ?? true);
            foreach (EntityExtractor::defaultDetectors() as $detector) {
                $detectors[] = match (true) {
                    $detector instanceof EmailDetector => new EmailDetector($keepAuthority),
                    $detector instanceof PhoneDetector => new PhoneDetector($keepAuthority),
                    default => $detector,
                };
            }
        }
        if ('regex' !== $detection) {
            if (null === $this->modelDetector) {
                throw new LearningFailedException('Model-based detection is not configured on this server.');
            }
            $detectors[] = ($this->modelDetector)();
        }

        $types = isset($body['types']) ? array_map(
            static fn (string $t): EntityType => EntityType::tryFrom($t) ?? throw new \InvalidArgumentException(\sprintf('Unknown type "%s".', $t)),
            self::strings($body, 'types'),
        ) : null;

        return null === $types ? $detectors : array_map(static fn (DetectorInterface $d): DetectorInterface => new RequestScopedDetector($d, $types), $detectors);
    }

    private function authorized(?string $authorization, string $remoteAddress): bool
    {
        if (null === $this->token || '' === $this->token) {
            return DemoServices::isLocalPeer($remoteAddress);
        }

        return null !== $authorization && hash_equals('Bearer '.$this->token, $authorization);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function text(array $body): string
    {
        $text = $body['text'] ?? null;
        if (!\is_string($text)) {
            throw new \InvalidArgumentException('"text" must be a string.');
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('"text" is longer than %d characters.', self::MAX_TEXT_LENGTH));
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private static function strings(array $body, string $key): array
    {
        $value = $body[$key] ?? [];
        $strings = [];
        foreach (\is_array($value) ? $value : [null] as $item) {
            if (!\is_string($item)) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be a list of strings.', $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Response
     */
    private static function ok(array $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return Response
     */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
