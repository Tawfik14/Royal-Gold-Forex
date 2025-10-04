<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ExchangeApi
{
    private const ENDPOINT = 'https://cdn.jsdelivr.net/gh/fawazahmed0/exchange-api@latest/currencies/eur.json';
    private HttpClientInterface $http;
    private CacheInterface $cache;

    public function __construct(HttpClientInterface $http, CacheInterface $cache)
    {
        $this->http  = $http;
        $this->cache = $cache;
    }

    /**
     * Retourne [ 'spots' => [ 'USD' => 1.091234, ... ], 'date' => 'YYYY-MM-DD' ]
     * Optionnel: $filterCodes = ['USD','GBP',...] pour ne garder que certaines devises.
     */
    public function getSpots(?array $filterCodes = null): array
    {
        // Cache 5 minutes pour éviter de frapper l'API à chaque requête
        return $this->cache->get('fx_eur_json_v1', function (ItemInterface $item) use ($filterCodes) {
            $item->expiresAfter(300); // 5 min
            $res  = $this->http->request('GET', self::ENDPOINT, ['timeout' => 8]);
            $json = $res->toArray(false);

            $date = $json['date'] ?? null;
            $eur  = $json['eur']  ?? [];

            // Normalise en UPPERCASE
            $spots = [];
            foreach ($eur as $code => $val) {
                $uc = strtoupper($code);
                if (!is_numeric($val)) continue;
                $spots[$uc] = (float) $val; // EUR -> CODE
            }

            // Filtre éventuel
            if (is_array($filterCodes) && $filterCodes) {
                $filter = [];
                foreach ($filterCodes as $c) {
                    $uc = strtoupper($c);
                    if (array_key_exists($uc, $spots)) {
                        $filter[$uc] = $spots[$uc];
                    }
                }
                $spots = $filter;
            }

            return [
                'spots' => $spots,
                'date'  => $date,
            ];
        });
    }
}

