<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class Csrf
{
    private string $appSecret;
    private RequestStack $requestStack;

    public function __construct(string $appSecret, RequestStack $requestStack)
    {
        $this->appSecret = $appSecret;
        $this->requestStack = $requestStack;
    }

    public function getToken(string $id): string
    {
        $session = $this->getSession();
        $seed = $session->get('_csrf_seed');
        if (!is_string($seed) || $seed === '') {
            $seed = bin2hex(random_bytes(32));
            $session->set('_csrf_seed', $seed);
        }
        $mac = hash_hmac('sha256', $id . ':' . $seed, $this->appSecret, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public function isValid(string $id, ?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = $this->getToken($id);
        return hash_equals($expected, $token);
    }

    private function getSession(): SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            throw new \RuntimeException('No current request available for CSRF.');
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        return $session;
    }
}
