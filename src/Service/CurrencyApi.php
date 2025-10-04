<?php
// src/Service/CurrencyApi.php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CurrencyApi
{
    // CDN principal recommandé par le repo
    private const CDN      = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json';
    // Fallback conseillé par le repo (hébergé sur Cloudflare Pages)
    private const FALLBACK = 'https://latest.currency-api.pages.dev/v1/currencies/eur.json';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
    ) {}

    /**
     * Retourne une map "USD" => 1.08, "GBP" => 0.84, ...
     * Les valeurs sont des taux MID pour EUR->CODE.
     */
    public function getEurSpots(int $ttlSeconds = 300): array
    {
        return $this->cache->get('eur_spots', function (ItemInterface $item) use ($ttlSeconds) {
            // Cache court (5 min) pour épargner le CDN
            $item->expiresAfter($ttlSeconds);

            $json = $this->fetchJson(self::CDN) ?? $this->fetchJson(self::FALLBACK);
            if (!$json || !isset($json['eur']) || !\is_array($json['eur'])) {
                // éviter d’empoisonner le cache si réponse KO
                $item->expiresAfter(30);
                return [];
            }

            // Normalisation en UPPERCASE
            $out = [];
            foreach ($json['eur'] as $code => $rate) {
                if (is_numeric($rate)) {
                    $out[\strtoupper($code)] = (float) $rate; // EUR -> CODE
                }
            }
            return $out;
        });
    }

    private function fetchJson(string $url): ?array
    {
        try {
            $res = $this->http->request('GET', $url, ['timeout' => 5]);
            if (200 !== $res->getStatusCode()) {
                return null;
            }
            /** @var array $data */
            $data = $res->toArray(false);
            return $data;
        } catch (\Throwable) {
            return null;
        }
    }
}

