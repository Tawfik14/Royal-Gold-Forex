<?php
// src/Controller/CurrencyController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Annotation\Route;

final class CurrencyController extends AbstractController
{
    public function __construct(private HttpClientInterface $http) {}

    #[Route('/devises', name: 'app_currencies', methods: ['GET'])]
    public function index(): Response
    {
        // 👉 Ta liste d’affichage (ajoute tes devises ici)
        $currencies = [
            ['code' => 'USD', 'country' => 'États-Unis',  'flag' => '🇺🇸'],
            ['code' => 'GBP', 'country' => 'Royaume-Uni', 'flag' => '🇬🇧'],
            ['code' => 'CHF', 'country' => 'Suisse',      'flag' => '🇨🇭'],
        ];

        // On va chercher les taux EUR→XXX
        $spots = [];
        $urls = [
            'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json',
            'https://latest.currency-api.pages.dev/v1/currencies/eur.json',
        ];

        foreach ($urls as $url) {
            try {
                $res = $this->http->request('GET', $url, ['timeout' => 8]);
                if (200 !== $res->getStatusCode()) continue;
                $data = $res->toArray(false);
                if (!isset($data['eur']) || !is_array($data['eur'])) continue;

                // Normalise en UPPERCASE
                foreach ($data['eur'] as $code => $val) {
                    if (is_numeric($val)) {
                        $spots[strtoupper($code)] = (float) $val; // EUR -> CODE
                    }
                }
                break; // on a des données, on s'arrête
            } catch (\Throwable $e) {
                // on tente l’URL suivante
            }
        }

        // Filtre: on ne garde que les codes qu’on affiche
        if ($spots) {
            $wanted = array_map(fn($c) => strtoupper($c['code']), $currencies);
            $spots = array_intersect_key($spots, array_flip($wanted));
        }

        return $this->render('exchange/index.html.twig', [
            'currencies' => $currencies,
            'spots'      => $spots, // <= utilisé par le Twig
        ]);
    }
}

