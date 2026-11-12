<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF protection for the MCP HTTP endpoint (/mcp), on top of the bundle's DNS rebinding check
 * (allowed_hosts/allowed_origins in config/packages/mcp.yaml).
 *
 * A web page can send a cross-site "simple" POST (text/plain, form data) without a CORS preflight,
 * and the response does not need to be readable for a tool call to have run. So /mcp takes JSON
 * only and no Origin other than this app or the MCP Inspector. Decided by the matched route, after routing.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class McpRequestGuard
{
    private const string MCP_ROUTE_PREFIX = '_mcp_endpoint_';

    /** Kept in sync with allowed_origins in config/packages/mcp.yaml. */
    public const array BROWSER_ORIGINS = ['http://localhost:6274', 'http://127.0.0.1:6274'];

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !\is_string($route) || !str_starts_with($route, self::MCP_ROUTE_PREFIX)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && $origin !== $request->getSchemeAndHttpHost() && !\in_array($origin, self::BROWSER_ORIGINS, true)) {
            $event->setResponse(new JsonResponse(['error' => 'Requests from other websites are not accepted.'], 403));

            return;
        }

        if ('POST' === $request->getMethod() && !str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            $event->setResponse(new JsonResponse(['error' => 'Send JSON (Content-Type: application/json).'], 415));
        }
    }
}
