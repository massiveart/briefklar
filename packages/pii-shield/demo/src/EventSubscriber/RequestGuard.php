<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo\EventSubscriber;

use Briefklar\PiiShield\Demo\DemoServices;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request checks after routing, decided by the matched route (not by path prefixes, which differ
 * from what the router matches for encoded paths), and security headers on every response.
 *
 * - Service mode (PII_SERVICE_ONLY=1, Docker): only the service routes (/v1, /mcp) exist.
 * - DNS rebinding: APIs answer known host names only (PII_SERVICE_ALLOWED_HOSTS adds more).
 * - CSRF: /v1, /mcp and /api take JSON only and no foreign Origin (/mcp also accepts the MCP Inspector's origin).
 * - /mcp needs the bearer token like /v1 (localhost only without a token).
 * - No wildcard CORS: no other website may read API responses.
 * - The demo API serves this machine only (real peer address, not just the Host header).
 */
final class RequestGuard implements EventSubscriberInterface
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];
    private const array SERVICE_ROUTES = ['v1', 'v1_openapi'];
    private const array DEMO_API_ROUTES = ['demo_api', 'demo_config', 'demo_samples'];
    private const string MCP_ROUTE_PREFIX = '_mcp_endpoint_';

    /** Browser-based MCP tools allowed besides same-origin requests (MCP Inspector's default). Kept in sync with the MCP bundle config. */
    public const array MCP_BROWSER_ORIGINS = ['http://localhost:6274', 'http://127.0.0.1:6274'];

    public function __construct(
        private readonly DemoServices $services,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 16], // after the router (32): the route is known
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $route = \is_string($route) ? $route : '';
        $isMcp = str_starts_with($route, self::MCP_ROUTE_PREFIX);
        $isService = $isMcp || \in_array($route, self::SERVICE_ROUTES, true);
        $isDemoApi = \in_array($route, self::DEMO_API_ROUTES, true);

        $deny = static fn (int $status, string $error) => $event->setResponse(new JsonResponse(['error' => $error], $status));

        if (DemoServices::serviceOnly() && !$isService) {
            $deny(404, 'Not found.');

            return;
        }
        if (!$isService && !$isDemoApi) {
            return; // the demo page and its assets
        }
        $host = (string) preg_replace('/:\d+$/', '', $request->getHttpHost());
        if (!\in_array($host, [...self::LOCAL_HOSTS, ...DemoServices::allowedHosts()], true)) {
            $deny(421, 'Unknown host.');

            return;
        }
        $origin = $request->headers->get('Origin');
        $foreignOrigin = null !== $origin && $origin !== $request->getSchemeAndHttpHost()
            && !($isMcp && \in_array($origin, self::MCP_BROWSER_ORIGINS, true));
        $notJson = 'POST' === $request->getMethod() && !str_starts_with((string) $request->headers->get('Content-Type'), 'application/json');
        if ($foreignOrigin || $notJson) {
            $deny(403, $isService ? 'Send JSON (Content-Type: application/json) from a non-browser client or this page.' : 'Only JSON requests from this page are accepted.');

            return;
        }
        if ($isMcp) {
            $this->guardMcp($request, $deny);

            return;
        }
        if ($isDemoApi && !self::fromLoopback($request)) {
            $deny(403, 'The demo API is available on this machine only.');
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        if ($event->getResponse() instanceof JsonResponse) {
            $headers->set('Cache-Control', 'no-store');
        }
        if ('*' === $headers->get('Access-Control-Allow-Origin')) {
            $headers->remove('Access-Control-Allow-Origin'); // never let any website read API responses
        }
    }

    /**
     * Same rule as /v1: bearer token if configured, otherwise localhost only; in service mode a token is required.
     *
     * @param \Closure(int, string): mixed $deny
     */
    private function guardMcp(Request $request, \Closure $deny): void
    {
        $token = $this->services->serviceToken();
        if (null === $token) {
            if (DemoServices::serviceOnly()) {
                $deny(503, 'Set PII_SERVICE_TOKEN to use this service.');
            } elseif (!self::fromLoopback($request)) {
                $deny(401, 'Missing or invalid bearer token.');
            }

            return;
        }
        if (!hash_equals('Bearer '.$token, (string) $request->headers->get('Authorization'))) {
            $deny(401, 'Missing or invalid bearer token.');
        }
    }

    private static function fromLoopback(Request $request): bool
    {
        return \in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }
}
