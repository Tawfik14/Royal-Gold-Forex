<?php

namespace App\Controller;

use App\Service\RateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ExchangeController extends AbstractController
{
    #[Route('/', name: 'app_exchange')]
    public function index(Request $request, RateService $rateService): Response
    {
        $mode = $request->query->get('mode', 'guest');

        $currencies = $rateService->getSupportedCurrencies();
        $defaultCode = $currencies[0]['code'] ?? 'USD';

        // Table des taux
        $rates = [];
        foreach ($currencies as $c) {
            $code = $c['code'];
            try {
                $rates[$code] = $rateService->computeBuySell($code);
            } catch (\Throwable $e) {
                $rates[$code] = [ 'mid' => null, 'buy' => null, 'sell' => null, 'error' => $e->getMessage() ];
            }
        }

        // Sélection de devise indépendante avec fallback croisé
        $buySelected  = $request->query->get('buy_currency',  $request->query->get('sell_currency', $defaultCode));
        $sellSelected = $request->query->get('sell_currency', $request->query->get('buy_currency',  $defaultCode));

        // Achat (EUR -> Local au taux SELL)
        $doBuy    = (bool) $request->query->get('do_buy', false);
        $buyEuro  = (float) $request->query->get('buy_eur', 0);
        $buyLocal = (float) $request->query->get('buy_local', 0);
        $buyResult = null;
        if ($doBuy && $buySelected) {
            try {
                $rs = $rateService->computeBuySell($buySelected);
                $sell = $rs['sell'] ?? null;
                if ($sell && $sell > 0) {
                    $calcEuro = null;
                    $calcLocal = null;
                    if ($buyEuro > 0 && $buyLocal <= 0) {
                        $calcLocal = $buyEuro * $sell;
                    } elseif ($buyLocal > 0 && $buyEuro <= 0) {
                        $calcEuro = $buyLocal / $sell;
                    }
                    if (($calcEuro !== null) || ($calcLocal !== null) || ($buyEuro > 0) || ($buyLocal > 0)) {
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

        // Vente (Local -> EUR au taux BUY)
        $doSell    = (bool) $request->query->get('do_sell', false);
        $sellEuro  = (float) $request->query->get('sell_eur', 0);
        $sellLocal = (float) $request->query->get('sell_local', 0);
        $sellResult = null;
        if ($doSell && $sellSelected) {
            try {
                $rs = $rateService->computeBuySell($sellSelected);
                $buy = $rs['buy'] ?? null;
                if ($buy && $buy > 0) {
                    $calcEuro = null;
                    $calcLocal = null;
                    if ($sellEuro > 0 && $sellLocal <= 0) {
                        $calcLocal = $sellEuro * $buy;
                    } elseif ($sellLocal > 0 && $sellEuro <= 0) {
                        $calcEuro = $sellLocal / $buy;
                    }
                    if (($calcEuro !== null) || ($calcLocal !== null) || ($sellEuro > 0) || ($sellLocal > 0)) {
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
}

