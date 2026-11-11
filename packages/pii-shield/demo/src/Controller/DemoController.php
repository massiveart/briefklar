<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo\Controller;

use Briefklar\PiiShield\Demo\DemoApi;
use Briefklar\PiiShield\Demo\DemoServices;
use Briefklar\PiiShield\Exception\DetectionFailedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The demo page and its JSON API (/api/*). Local use only; see RequestGuard.
 */
final class DemoController
{
    public function __construct(
        private readonly DemoApi $api,
        private readonly DemoServices $services,
        private readonly string $publicDir,
    ) {
    }

    #[Route('/', name: 'demo', methods: ['GET'])]
    public function page(): Response
    {
        return new Response((string) file_get_contents($this->publicDir.'/index.html'), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/api/config', name: 'demo_config', methods: ['GET', 'POST'])]
    public function config(): JsonResponse
    {
        return new JsonResponse($this->services->config());
    }

    #[Route('/api/samples', name: 'demo_samples', methods: ['GET'])]
    public function samples(): JsonResponse
    {
        $samples = [];
        $files = glob(\dirname($this->publicDir, 4).'/fixtures/letters/*.txt');
        foreach (false === $files ? [] : $files as $file) {
            $samples[] = ['name' => basename($file, '.txt'), 'text' => (string) file_get_contents($file)];
        }

        return new JsonResponse($samples);
    }

    #[Route('/api/{action}', name: 'demo_api', requirements: ['action' => 'analyze|restore|models|learn|entities|entities/forget'], methods: ['POST'])]
    public function api(Request $request, string $action): JsonResponse
    {
        set_time_limit(180); // local models can take a while
        $started = hrtime(true);
        $model = null;

        try {
            $body = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
            if (!\is_array($body)) {
                throw new \InvalidArgumentException('Expected a JSON object.');
            }
            /** @var array<string, mixed> $body */
            // With a service token, learned types change through /v1 only; a dry run writes nothing.
            if (null !== $this->services->serviceToken() && ('entities/forget' === $action || ('learn' === $action && true !== ($body['dryRun'] ?? false)))) {
                return new JsonResponse(['error' => 'Learned types are managed through the /v1 API with a token on this server.'], 403);
            }
            $model = self::modelLabel($body);

            $response = match ($action) {
                'analyze' => $this->api->analyze($body),
                'restore' => $this->api->restore($body),
                'learn' => $this->api->learn($body),
                'entities' => $this->api->entities(),
                'entities/forget' => $this->api->forget($body),
                default => ['models' => $this->services->models(\is_string($body['provider'] ?? null) ? $body['provider'] : '', \is_string($body['endpoint'] ?? null) ? $body['endpoint'] : null)],
            };
            $this->log($action, 200, $started, $model);

            return new JsonResponse($response);
        } catch (\InvalidArgumentException|\JsonException $e) {
            $this->log($action, 400, $started, $model, $e->getMessage());

            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (DetectionFailedException $e) {
            $answer = $e->modelAnswer ?? $e->getPrevious()?->getMessage();
            $this->log($action, 502, $started, $model, $e->getMessage(), $answer);

            // Debug mode (local demo, synthetic data): the raw answer is shown in the page log.
            return new JsonResponse(DemoServices::debug()
                ? ['error' => $e->getMessage(), 'modelAnswer' => $answer]
                : ['error' => $e->getMessage().' Set PII_DEMO_DEBUG=1 in demo/.env.local to see the model answer.'], 502);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function modelLabel(array $body): ?string
    {
        $model = $body['model'] ?? null;
        if (!\is_array($model) || 'regex' === ($body['detection'] ?? 'regex')) {
            return null;
        }

        return \sprintf('%s/%s', \is_string($model['provider'] ?? null) ? $model['provider'] : '?', \is_string($model['model'] ?? null) ? $model['model'] : '?');
    }

    /**
     * Request log (var/log/demo-YYYY-MM-DD.log, 7 days): what was asked, how long it took, what failed. Never the text.
     */
    private function log(string $action, int $status, int|float $started, ?string $model, ?string $error = null, ?string $modelAnswer = null): void
    {
        $this->services->log(array_filter([
            'path' => '/api/'.$action,
            'status' => $status,
            'ms' => round((hrtime(true) - $started) / 1e6, 1),
            'model' => $model,
            'error' => $error,
            'modelAnswer' => DemoServices::debug() ? $modelAnswer : null,
        ], static fn (mixed $v): bool => null !== $v));
    }
}
