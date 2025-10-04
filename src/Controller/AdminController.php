<?php

namespace App\Controller;

use App\Entity\DisplayConfig;
use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\Reservation;
use App\Repository\ContactMessageRepository;
use App\Repository\DisplayConfigRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RateRuleRepository;
use App\Repository\ReservationRepository;
use App\Service\PdfService;
use App\Service\RateService;
use App\Service\CurrencyApi; // spots EUR->CODE (API publique)
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    #[Route('/admin/reservations', name: 'admin_reservations')]
    public function reservations(Request $request, ReservationRepository $repo): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }

        $rows = $repo->createQueryBuilder('r')->orderBy('r.createdAt', 'DESC')->getQuery()->getResult();

        return $this->render('admin/reservations.html.twig', [
            'mode' => $mode,
            'rows' => $rows,
            'now'  => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/admin/reservations/{id}/confirm', name: 'admin_reservation_confirm', methods: ['POST'])]
    public function confirm(int $id, ReservationRepository $repo, EntityManagerInterface $em, Request $request): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }
        $r = $repo->find($id);
        if (!$r) {
            $this->addFlash('error', 'Réservation introuvable.');
        } else {
            $r->setStatus(Reservation::STATUS_COMPLETED);
            $em->flush();
            $this->addFlash('success', 'Réservation confirmée (transaction passée).');
        }
        return $this->redirectToRoute('admin_reservations', ['mode' => 'admin']);
    }

    #[Route('/admin/messages', name: 'admin_messages')]
    public function messages(Request $request, ContactMessageRepository $repo): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }
        $rows = $repo->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')->getQuery()->getResult();

        return $this->render('admin/messages.html.twig', [
            'mode' => $mode,
            'rows' => $rows,
        ]);
    }

    #[Route('/admin/rates', name: 'admin_rates', methods: ['GET','POST'])]
    public function rates(
        Request $request,
        RateService $rateService,
        RateRuleRepository $ruleRepo,
        EntityManagerInterface $em,
        CurrencyApi $api
    ): Response
    {
        $adminMode = $request->query->get('mode', 'guest');
        if ($adminMode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $adminMode]);
        }

        // Liste des devises supportées
        $codes = array_map(static fn(array $c) => $c['code'], $rateService->getSupportedCurrencies());

        // Règles existantes (par code)
        $rules = [];
        foreach ($codes as $code) {
            $rules[$code] = $ruleRepo->findOneByCode($code);
        }

        // Spots EUR->CODE (API publique, mise en cache dans le service)
        $spots = $api->getEurSpots(300); // ['USD'=>1.08, 'GBP'=>0.84, ...]

        if ($request->isMethod('POST')) {
            $payload  = $request->request->all();
            $errors   = [];
            $prepared = []; // lignes prêtes à être persistées (touchées + valides)

            foreach ($codes as $code) {
                // Valeurs brutes reçues
                $rawMode = $payload['mode_'.$code] ?? null; // null / '' / 'manual' / 'percent'
                $rawMb   = $payload['mbuy_'.$code]  ?? '';
                $rawMs   = $payload['msell_'.$code] ?? '';
                $rawPb   = $payload['pbuy_'.$code]  ?? '';
                $rawPs   = $payload['psell_'.$code] ?? '';

                // Mode existant en base
                $existing    = $rules[$code] ?? null;
                $currentMode = $existing ? $existing->getMode() : null; // 'manual' | 'percent' | null

                // Ligne touchée ?
                $hasManualInput  = trim((string)$rawMb) !== '' || trim((string)$rawMs) !== '';
                $hasPercentInput = trim((string)$rawPb) !== '' || trim((string)$rawPs) !== '';
                $modeInPayload   = \in_array($rawMode, ['manual','percent'], true) ? $rawMode : null;
                $modeChanged     = ($modeInPayload !== null && $modeInPayload !== $currentMode);

                // Non touchée -> on ignore
                if (!$hasManualInput && !$hasPercentInput && !$modeChanged) {
                    continue;
                }

                // Déterminer le mode si non fourni
                $mode = $modeInPayload;
                if ($mode === null) {
                    if ($hasManualInput && !$hasPercentInput) {
                        $mode = 'manual';
                    } elseif ($hasPercentInput && !$hasManualInput) {
                        $mode = 'percent';
                    } else {
                        $errors[] = "[$code] Choisissez un mode (Manuel ou Pourcentage).";
                        continue;
                    }
                }

                // Normalisation décimaux
                $manualBuy   = $this->toNullableFloat($rawMb);
                $manualSell  = $this->toNullableFloat($rawMs);
                $percentBuy  = $this->toNullableFloat($rawPb);
                $percentSell = $this->toNullableFloat($rawPs);

                if ($mode === 'manual') {
                    if (!($manualBuy  > 0))  { $errors[] = "[$code] EUR→Local Achat (> 0) requis en mode Manuel."; }
                    if (!($manualSell > 0))  { $errors[] = "[$code] EUR→Local Vente (> 0) requis en mode Manuel."; }
                    $percentBuy = $percentSell = null;
                } else { // percent
                    // Si un seul % saisi → on duplique vers l’autre
                    if ($percentBuy !== null && $percentSell === null) {
                        $percentSell = $percentBuy;
                    } elseif ($percentSell !== null && $percentBuy === null) {
                        $percentBuy = $percentSell;
                    }
                    if ($percentBuy === null || $percentSell === null) {
                        $errors[] = "[$code] Renseignez au moins un pourcentage (l’autre sera recopié).";
                    }
                    $manualBuy = $manualSell = null;
                }

                // Pas d'erreur pour ce code → préparer
                if (empty($errors) || !str_contains(end($errors), "[$code]")) {
                    $prepared[$code] = [
                        'mode'        => $mode,
                        'manualBuy'   => $manualBuy,
                        'manualSell'  => $manualSell,
                        'percentBuy'  => $percentBuy,
                        'percentSell' => $percentSell,
                    ];
                }
            }

            // Erreurs → affichage, pas d'enregistrement
            if (!empty($errors)) {
                foreach ($errors as $msg) {
                    $this->addFlash('error', $msg);
                }
                return $this->render('admin/rates.html.twig', [
                    'mode'  => 'admin',
                    'codes' => $codes,
                    'rules' => $rules,               // on garde l’état actuel
                    'spots' => $api->getEurSpots(300),
                ]);
            }

            // Persist seulement les lignes touchées et valides
            foreach ($prepared as $code => $vals) {
                $rule = $ruleRepo->findOneByCode($code) ?? (new \App\Entity\RateRule())->setCode($code);
                $rule->setMode($vals['mode']);
                $rule->setManualBuy($vals['manualBuy']);
                $rule->setManualSell($vals['manualSell']);
                $rule->setPercentBuy($vals['percentBuy']);
                $rule->setPercentSell($vals['percentSell']);
                $rule->touch();
                $em->persist($rule);
            }

            $em->flush();
            $this->addFlash('success', 'Taux mis à jour.');
            return $this->redirectToRoute('admin_rates', ['mode' => 'admin']);
        }

        return $this->render('admin/rates.html.twig', [
            'mode'  => 'admin',
            'codes' => $codes,
            'rules' => $rules,
            'spots' => $spots,
        ]);
    }

    #[Route('/admin/invoice', name: 'admin_invoice', methods: ['GET','POST'])]
    public function invoice(
        Request $request,
        RateService $rates,
        EntityManagerInterface $em,
        InvoiceRepository $repo
    ): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }

        if ($request->isMethod('POST')) {
            // Normalisation nom/prénom
            $firstRaw = (string)$request->request->get('first_name', '');
            $lastRaw  = (string)$request->request->get('last_name', '');
            $first = $this->normalizeFirstName($firstRaw);
            $last  = $this->normalizeLastName($lastRaw);

            // Date de naissance saisie libre
            $dobStr = trim((string)$request->request->get('dob', ''));
            $dob    = $this->parseDobFlexible($dobStr);

            $addr = trim((string)$request->request->get('address', ''));
            $pay  = (string)$request->request->get('payment', 'cash');

            // Validation
            $dobOk = $dob instanceof \DateTimeImmutable;
            if ($dobOk) {
                $today = new \DateTimeImmutable('today');
                $dobOk = ($dob >= new \DateTimeImmutable('1900-01-01')) && ($dob <= $today);
            }
            if (!$first || !$last || !$dobOk || !$addr) {
                $this->addFlash('error', 'Veuillez compléter les informations client (date valide, ex. 01/01/2000).');
                return $this->redirectToRoute('admin_invoice', ['mode' => 'admin']);
            }

            $inv = (new Invoice())
                ->setFirstName($first)
                ->setLastName($last)
                ->setDateOfBirth($dob)
                ->setAddress($addr)
                ->setPaymentMethod(\in_array($pay, ['cash','card','transfer'], true) ? $pay : 'cash')
                ->setInvoiceCode(self::randomCode());

            $codes   = (array)$request->request->all('item_currency');
            $euros   = (array)$request->request->all('item_eur');
            $locals  = (array)$request->request->all('item_local');
            $rELs    = (array)$request->request->all('item_rate_el'); // EUR->Local
            $rLEs    = (array)$request->request->all('item_rate_le'); // Local->EUR

            $added = 0;
            foreach ($codes as $i => $code) {
                $code = trim((string)$code);
                if ($code === '') continue;

                $eur = trim((string)($euros[$i] ?? ''));
                $loc = trim((string)($locals[$i] ?? ''));
                $rel = trim((string)($rELs[$i] ?? ''));
                $rle = trim((string)($rLEs[$i] ?? ''));

                // Normalise aussi ici si besoin, sinon laisse tel quel
                $eurF = $eur !== '' ? (float)str_replace(',', '.', str_replace(' ', '', $eur)) : null;
                $locF = $loc !== '' ? (float)str_replace(',', '.', str_replace(' ', '', $loc)) : null;
                $relF = $rel !== '' ? (float)str_replace(',', '.', str_replace(' ', '', $rel)) : null;
                $rleF = $rle !== '' ? (float)str_replace(',', '.', str_replace(' ', '', $rle)) : null;

                // Si un taux manque retourner aux taux du jour
                if ($relF === null || $rleF === null) {
                    $r = $rates->computeBuySell($code);
                    if ($relF === null && $r['sell']) $relF = (float)$r['sell'];                 // EUR->Local (SELL)
                    if ($rleF === null && $r['buy'] && $r['buy'] > 0) $rleF = 1.0 / (float)$r['buy']; // Local->EUR (1/BUY)
                }

                // calcul montants
                if ($eurF !== null && $locF === null && $relF !== null) $locF = $eurF * $relF;
                if ($locF !== null && $eurF === null && $rleF !== null && $rleF > 0) $eurF = $locF * $rleF;

                if (($eurF ?? 0) <= 0 && ($locF ?? 0) <= 0) continue;

                $item = (new InvoiceItem())
                    ->setCurrency($code)
                    ->setAmountEuro($eurF !== null ? number_format($eurF, 2, '.', '') : null)
                    ->setAmountLocal($locF !== null ? number_format($locF, 2, '.', '') : null)
                    ->setRateEurToLocal($relF)
                    ->setRateLocalToEur($rleF);
                $inv->addItem($item);
                $added++;
            }

            if ($added === 0) {
                $this->addFlash('error', 'Ajoutez au moins une ligne de devise.');
                return $this->redirectToRoute('admin_invoice', ['mode' => 'admin']);
            }

            $em->persist($inv);
            $em->flush();

            return $this->redirectToRoute('admin_invoice_pdf', ['id' => $inv->getId(), 'mode' => 'admin']);
        }

        return $this->render('admin/invoice.html.twig', [
            'mode' => 'admin',
            'currencies' => $rates->getSupportedCurrencies(),
            'baseQuotes' => (function() use ($rates){
                $out = [];
                foreach ($rates->getSupportedCurrencies() as $c) {
                    $code = $c['code'];
                    $r = $rates->computeBuySell($code);
                    $out[$code] = [
                        'buy'  => $r['buy']  ?? null, // Achat (bureau achète la devise du client)
                        'sell' => $r['sell'] ?? null, // Vente (bureau vend la devise au client)
                    ];
                }
                return $out;
            })(),
        ]);
    }

    #[Route('/admin/invoice/{id}/pdf', name: 'admin_invoice_pdf', methods: ['GET'])]
    public function invoicePdf(Invoice $invoice, PdfService $pdf): Response
    {
        $content = $pdf->renderInvoicePdf('admin/invoice_pdf.html.twig', ['invoice' => $invoice]);
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('Invoice_%s.pdf', $invoice->getInvoiceCode()));
        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }

    #[Route('/admin/display', name: 'admin_display', methods: ['GET','POST'])]
    public function displayConfig(
        Request $request,
        DisplayConfigRepository $repo,
        EntityManagerInterface $em,
        RateService $rateService
    ): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }

        $config = $repo->findOneBy([]) ?? new DisplayConfig();

        if ($request->isMethod('POST')) {
            $direction = (string) $request->request->get('direction', 'eur_to_local');
            $codes = (array) $request->request->all('codes');

            $config->setDirection($direction);
            $config->setCodes($codes);

            $em->persist($config);
            $em->flush();

            $this->addFlash('success', 'Affichage mis à jour.');
            return $this->redirectToRoute('admin_display', ['mode' => 'admin']);
        }

        return $this->render('admin/display.html.twig', [
            'mode' => 'admin',
            'currencies' => $rateService->getSupportedCurrencies(),
            'selected' => $config->getCodes(),
            'direction' => $config->getDirection(),
        ]);
    }

    #[Route('/admin', name: 'admin_dashboard')]
    public function dashboard(Request $request): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            $this->addFlash('error', 'Accès réservé à l’administrateur.');
            return $this->redirectToRoute('app_exchange', ['mode' => $mode]);
        }

        return $this->render('admin/dashboard.html.twig', [
            'mode' => 'admin',
        ]);
    }

    #[Route('/admin/screen', name: 'admin_screen', methods: ['GET'])]
    public function screen(
        Request $request,
        DisplayConfigRepository $repo,
        RateService $rateService
    ): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($mode !== 'admin') {
            return new Response('Forbidden', 403);
        }

        $config = $repo->findOneBy([]);
        $direction = $config ? $config->getDirection() : 'eur_to_local';

        $supported = $rateService->getSupportedCurrencies();
        $byCode = [];
        foreach ($supported as $c) {
            $byCode[$c['code']] = $c;
        }

        $codes = $config && $config->getCodes() ? $config->getCodes() : array_column($supported, 'code');

        $rows = [];
        foreach ($codes as $code) {
            if (!isset($byCode[$code])) {
                continue;
            }

            $meta = $byCode[$code];
            try {
                $r = $rateService->computeBuySell($code);
                $buy = $r['buy'] ?? null;
                $sell = $r['sell'] ?? null;

                if ($direction === 'local_to_eur') {
                    $displayBuy = ($buy && $buy > 0) ? (1 / $buy) : null;
                    $displaySell = ($sell && $sell > 0) ? (1 / $sell) : null;
                    $prefixCode = $code;
                    $suffixCode = 'EUR';
                } else {
                    $displayBuy = $buy;
                    $displaySell = $sell;
                    $prefixCode = 'EUR';
                    $suffixCode = $code;
                }

                $rows[] = [
                    'code' => $code,
                    'flag' => $meta['flag'] ?? '',
                    'name' => $meta['country'] ?? ($meta['name'] ?? $code),
                    'displayBuy' => $displayBuy,
                    'displaySell' => $displaySell,
                    'prefixCode' => $prefixCode,
                    'suffixCode' => $suffixCode,
                ];
            } catch (\Throwable $e) {
                $rows[] = [
                    'code' => $code,
                    'flag' => $meta['flag'] ?? '',
                    'name' => $meta['country'] ?? ($meta['name'] ?? $code),
                    'displayBuy' => null,
                    'displaySell' => null,
                    'prefixCode' => $direction === 'local_to_eur' ? $code : 'EUR',
                    'suffixCode' => $direction === 'local_to_eur' ? 'EUR' : $code,
                ];
            }
        }

        return $this->render('admin/screen.html.twig', [
            'rows' => $rows,
            'direction' => $direction,
        ]);
    }

    private static function randomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i=0;$i<10;$i++) $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
        return $out;
    }

    private function normalizeFirstName(string $value): string
    {
        $v = trim($value);
        if ($v === '') return '';
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = mb_strtolower($v, 'UTF-8');
        return preg_replace_callback('/\p{L}[\p{L}]*/u', function($m){
            $w = $m[0];
            return mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($w, 1, null, 'UTF-8');
        }, $v);
    }

    private function normalizeLastName(string $value): string
    {
        $v = trim($value);
        if ($v === '') return '';
        $v = preg_replace('/\s+/u', ' ', $v);
        return mb_strtoupper($v, 'UTF-8');
    }

    private function parseDobFlexible(string $input): ?\DateTimeImmutable
    {
        $v = trim($input);
        if ($v === '') return null;
        $v = preg_replace('/[.\-\s]+/u', '/', $v);
        $formats = ['!d/m/Y', '!d/m/y', '!Y/m/d'];
        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $v);
            if ($dt instanceof \DateTimeImmutable) {
                $errs = \DateTimeImmutable::getLastErrors();
                if (empty($errs['warning_count']) && empty($errs['error_count'])) {
                    return $dt->setTime(0,0,0);
                }
            }
        }
        return null;
    }

    /**
     * Normalise une saisie décimale (accepte virgule/point, espaces).
     * Retourne null si vide ou invalide.
     */
    private function toNullableFloat(mixed $v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = str_replace(["\xC2\xA0", ' '], '', $s); // espace insécable + espaces
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
}

