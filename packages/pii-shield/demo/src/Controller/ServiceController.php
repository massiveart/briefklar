<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo\Controller;

use Briefklar\PiiShield\Demo\ServiceApi;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP API for apps (/v1), documented in docs/openapi.yaml. All logic lives in ServiceApi.
 */
final class ServiceController
{
    public function __construct(
        private readonly ServiceApi $api,
    ) {
    }

    #[Route('/v1/openapi.yaml', name: 'v1_openapi', methods: ['GET'])]
    public function openapi(): Response
    {
        $packaged = \dirname(__DIR__, 3).'/docs/openapi.yaml';
        $installed = \dirname(__DIR__, 2).'/vendor/briefklar/pii-shield/docs/openapi.yaml';

        return new Response((string) file_get_contents(is_file($packaged) ? $packaged : $installed), 200, ['Content-Type' => 'application/yaml; charset=utf-8']);
    }

    #[Route('/v1/{path}', name: 'v1', requirements: ['path' => '.+'], priority: -1)]
    public function handle(Request $request, string $path): Response
    {
        set_time_limit(180); // local models can take a while

        $body = null;
        if ('POST' === $request->getMethod()) {
            try {
                $body = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return new JsonResponse(['error' => 'Invalid JSON.'], 400);
            }
            if (!\is_array($body)) {
                return new JsonResponse(['error' => 'Expected a JSON object.'], 400);
            }
        }

        /** @var array<string, mixed>|null $body */
        $response = $this->api->handle($request->getMethod(), '/v1/'.$path, $body, $request->headers->get('Authorization'), (string) $request->getClientIp());

        if (204 === $response['status']) {
            return new Response(null, 204);
        }
        $json = new JsonResponse($response['body'], $response['status']);

        return $json->setEncodingOptions($json->getEncodingOptions() | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES); // readable with curl
    }
}
