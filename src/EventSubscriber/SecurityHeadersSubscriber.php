<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();

        // Only set for master requests; Symfony 6+ has isMainRequest
        if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
            return;
        }

        // Basic hardening headers
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('Referrer-Policy', 'no-referrer', false);
        $response->headers->set('X-XSS-Protection', '0', false); // obsolete, but explicitly disabled

        // HSTS only over HTTPS
        if ($request->isSecure()) {
            // IncludeSubDomains & preload can be toggled if needed
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains; preload', false);
        }

        // Content Security Policy (adjust as needed for Google Fonts, inline styles/scripts)
        $csp = [];
        $csp[] = "default-src 'self'";
        $csp[] = "img-src 'self' data:";
        $csp[] = "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com";
        $csp[] = "font-src 'self' https://fonts.gstatic.com data:";
        $csp[] = "script-src 'self' 'unsafe-inline'";
        $csp[] = "connect-src 'self'";
        $csp[] = "frame-ancestors 'none'"; // disallow embedding

        $response->headers->set('Content-Security-Policy', implode('; ', $csp), false);
    }
}
