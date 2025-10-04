<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\RateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ExchangeController extends AbstractController
{
    #[Route('/', name: 'app_exchange')]
    public function index(Request $request, RateService $rateService): Response
    {
        $mode = (string) $request->query->get('mode', 'guest');

        // Devises disponibles
        $currencies = $rateService->getSupportedCurrencies();
        if (empty($currencies)) {
            // On affiche quand même la page avec un flash
            $this->addFlash('error', 'Aucune devise disponible pour le moment.');
            $currencies = [];
        }

        // Code par défaut
        $defaultCode = $currencies[0]['code'] ?? 'USD';

        // Table des taux (affichage des lignes sur la grille)
        $rates = [];
        foreach ($currencies as $c) {
            $code = $c['code'];
            try {
                $rates[$code] = $rateService->computeBuySell($code);
            } catch (\Throwable $e) {
                $rates[$code] = [
                    'mid'   => null,
                    'buy'   => null,
                    'sell'  => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $buySelected  = $request->query->get('buy_currency',  $request->query->get('sell_currency', $defaultCode));
        $sellSelected = $request->query->get('sell_currency', $request->query->get('buy_currency',  $defaultCode));

        $doBuy = (bool) $request->query->get('do_buy', false);
        $doSell = (bool) $request->query->get('do_sell', false);
        $buyEuro = (float) $request->query->get('buy_eur', 0);
        $buyLocal = (float) $request->query->get('buy_local', 0);
        $sellEuro = (float) $request->query->get('sell_eur', 0);
        $sellLocal = (float) $request->query->get('sell_local', 0);
        $buyResult = null;
        $sellResult = null;

        if ($doBuy && $buySelected) {
            try {
                $rs = $rateService->computeBuySell($buySelected);
                $sell = $rs['sell'] ?? null; // EUR -> Local (vente de l’agence)
                if ($sell && $sell > 0) {
                    $calcEuro = null;
                    $calcLocal = null;
                    if ($buyEuro > 0 && $buyLocal <= 0) {
                        $calcLocal = $buyEuro * $sell;
                    } elseif ($buyLocal > 0 && $buyEuro <= 0) {
                        $calcEuro = $buyLocal / $sell;
                    }
                    if ($calcEuro !== null || $calcLocal !== null || $buyEuro > 0 || $buyLocal > 0) {
                        $buyResult = [
                            'eur'   => ($buyEuro > 0 ? $buyEuro : ($calcEuro ?? 0)),
                            'local' => ($buyLocal > 0 ? $buyLocal : ($calcLocal ?? 0)),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        if ($doSell && $sellSelected) {
            try {
                $rs = $rateService->computeBuySell($sellSelected);
                $buy = $rs['buy'] ?? null; // Local -> EUR (achat de l’agence)
                if ($buy && $buy > 0) {
                    $calcEuro = null;
                    $calcLocal = null;
                    if ($sellEuro > 0 && $sellLocal <= 0) {
                        $calcLocal = $sellEuro * $buy;
                    } elseif ($sellLocal > 0 && $sellEuro <= 0) {
                        $calcEuro = $sellLocal / $buy;
                    }
                    if ($calcEuro !== null || $calcLocal !== null || $sellEuro > 0 || $sellLocal > 0) {
                        $sellResult = [
                            'eur'   => ($sellEuro > 0 ? $sellEuro : ($calcEuro ?? 0)),
                            'local' => ($sellLocal > 0 ? $sellLocal : ($calcLocal ?? 0)),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('exchange/index.html.twig', [
            'mode'         => $mode,
            'currencies'   => $currencies,
            'rates'        => $rates,
            'buySelected'  => $buySelected,
            'sellSelected' => $sellSelected,
            'buyEuro'      => $buyEuro,
            'buyLocal'     => $buyLocal,
            'buyResult'    => $buyResult,
            'sellEuro'     => $sellEuro,
            'sellLocal'    => $sellLocal,
            'sellResult'   => $sellResult,
        ]);
    }

    /**
     * Version propre de l’URL : /convertisseur/USD
     * - Verrouille la page sur la devise du paramètre.
     */
    #[Route('/convertisseur/{code}', name: 'app_converter', requirements: ['code' => '[A-Za-z]{3}'])]
    public function converter(string $code, Request $request, RateService $rateService): Response
    {
        $mode = (string) $request->query->get('mode', 'guest');

        // Vérifier que des devises existent
        $currencies = $rateService->getSupportedCurrencies();
        if (empty($currencies)) {
            $this->addFlash('error', 'Aucune devise disponible pour le moment.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode ?: 'guest']);
        }

        $code = strtoupper($code);

        // Récupérer la "fiche" courte (nom/pays/flag/spread)
        $current = $rateService->getCurrencyMeta($code);
        if (!$current) {
            $this->addFlash('error', sprintf('La devise "%s" est inconnue.', $code));
            return $this->redirectToRoute('app_exchange', ['mode' => $mode ?: 'guest']);
        }

        // Récupérer le taux pour cette devise
        try {
            $rate = $rateService->computeBuySell($code); 
        } catch (\Throwable $e) {
            $rate = ['mid' => null, 'buy' => null, 'sell' => null, 'error' => $e->getMessage()];
            $this->addFlash('error', 'Impossible de récupérer le taux pour '.$code);
        }

        // --- NOUVEAU : gros contenu éditorial plein texte ---
        $article = $rateService->getCurrencyArticle($code);

        return $this->render('exchange/converter.html.twig', [
            'mode'         => $mode,
            'current'      => $current,
            'code'         => $code,
            'rate'         => $rate,
            'buySelected'  => $code,
            'sellSelected' => $code,
            'article'      => $article, // <<<<<< injecté dans le template
        ]);
    }

    #[Route('/convertisseur', name: 'app_converter_query')]
    public function converterQuery(Request $request): Response
    {
        $mode = (string) $request->query->get('mode', 'guest');
        $code = strtoupper((string) $request->query->get('code', ''));

        if ($code) {
            return $this->redirectToRoute('app_converter', ['code' => $code, 'mode' => $mode ?: 'guest']);
        }

        $this->addFlash('error', 'Veuillez sélectionner une devise depuis la page Devises.');
        return $this->redirectToRoute('app_exchange', ['mode' => $mode ?: 'guest']);
    }
}

