<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo\EventSubscriber;

use Briefklar\PiiShield\Demo\DemoServices;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request checks before routing, security headers on every response.
 *
 * - Service mode (PII_SERVICE_ONLY=1, Docker): only /v1 exists.
 * - DNS rebinding: /api and /v1 answer known host names only (PII_SERVICE_ALLOWED_HOSTS adds more).
 * - CSRF: JSON only and no foreign Origin, so other websites in the browser cannot drive the APIs.
 * - The demo API (/api) serves this machine only (real peer address, not just the Host header).
 */
final class RequestGuard implements EventSubscriberInterface
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 64], // before routing
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $isService = str_starts_with($path, '/v1/');
        $isDemoApi = str_starts_with($path, '/api/');

        $deny = static fn (int $status, string $error) => $event->setResponse(new JsonResponse(['error' => $error], $status));

        if (DemoServices::serviceOnly() && !$isService) {
            $deny(404, 'Not found.');

            return;
        }
        if (!$isService && !$isDemoApi) {
            return;
        }
        $host = (string) preg_replace('/:\d+$/', '', $request->getHttpHost());
        if (!\in_array($host, [...self::LOCAL_HOSTS, ...DemoServices::allowedHosts()], true)) {
            $deny(421, 'Unknown host.');

            return;
        }
        $origin = $request->headers->get('Origin');
        $foreignOrigin = null !== $origin && $origin !== $request->getSchemeAndHttpHost();
        $notJson = 'POST' === $request->getMethod() && !str_starts_with((string) $request->headers->get('Content-Type'), 'application/json');
        if ($foreignOrigin || $notJson) {
            $deny(403, $isService ? 'Send JSON (Content-Type: application/json) from a non-browser client or this page.' : 'Only JSON requests from this page are accepted.');

            return;
        }
        if ($isDemoApi && !\in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
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
    }
}
