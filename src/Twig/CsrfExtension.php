<?php

namespace App\Twig;

use App\Service\Csrf;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CsrfExtension extends AbstractExtension
{
    public function __construct(private readonly Csrf $csrf) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', [$this, 'token']),
        ];
    }

    public function token(string $id): string
    {
        return $this->csrf->getToken($id);
    }
}
