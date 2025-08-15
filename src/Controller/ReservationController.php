<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\ReservationItem;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use App\Service\RateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReservationController extends AbstractController
{
    #[Route('/reservation', name: 'app_reservation', methods: ['GET','POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $users,
        ReservationRepository $reservations,
        RateService $rateService
    ): Response
    {
        $mode = $request->query->get('mode', 'guest');
        if ($request->isMethod('POST')) {
            if ($mode === 'guest') {
                $this->addFlash('error', 'Connectez-vous pour réserver.');
                return $this->redirectToRoute('app_reservation', ['mode' => $mode]);
            }

            $auth = $request->getSession()->get('auth_user');
            $user = $auth ? $users->find($auth['id'] ?? 0) : null;
            if (!$user) {
                $this->addFlash('error', 'Session expirée. Reconnectez-vous.');
                return $this->redirectToRoute('app_reservation', ['mode' => $mode]);
            }

            $firstName = trim((string) $request->request->get('first_name', ''));
            $lastName  = trim((string) $request->request->get('last_name', ''));
            $operation = $request->request->get('operation', 'buy'); // 'buy' | 'sell'

            if (!$firstName || !$lastName || !in_array($operation, ['buy','sell'], true)) {
                $this->addFlash('error', 'Veuillez renseigner prénom, nom et l’opération.');
                return $this->redirectToRoute('app_reservation', ['mode' => $mode]);
            }

            $res = (new Reservation())
                ->setUser($user)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setOperation($operation)
                ->setOrderCode(self::generateOrderCode())
                ->setPickupDeadline(self::todayAt19());

            // Lire lignes dynamiques
            $currencies = (array) $request->request->all('item_currency');
            $amountsEur = (array) $request->request->all('item_eur');
            $amountsLoc = (array) $request->request->all('item_local');

            $added = 0;
            foreach ($currencies as $i => $code) {
                $code = trim((string) $code);
                if ($code === '') continue;

                $eur = trim((string) ($amountsEur[$i] ?? ''));
                $loc = trim((string) ($amountsLoc[$i] ?? ''));

                $eurVal = $eur !== '' ? (float) $eur : null;
                $locVal = $loc !== '' ? (float) $loc : null;

                $rates = $rateService->computeBuySell($code);
                if (!$rates['buy'] || !$rates['sell']) {
                    $this->addFlash('error', "Taux indisponibles pour $code.");
                    continue;
                }

                // Compléter montant manquant selon l’opération
                if ($operation === 'buy') {
                    // Client paie EUR -> reçoit devise
                    if ($eurVal !== null && $locVal === null) $locVal = $eurVal * (float) $rates['sell'];
                    elseif ($locVal !== null && $eurVal === null) $eurVal = $locVal / (float) $rates['sell'];
                } else { // sell
                    // Client donne devise -> reçoit EUR
                    if ($eurVal !== null && $locVal === null) $locVal = $eurVal * (float) $rates['buy'];
                    elseif ($locVal !== null && $eurVal === null) $eurVal = $locVal / (float) $rates['buy'];
                }

                if (($eurVal ?? 0) <= 0 && ($locVal ?? 0) <= 0) {
                    continue; // ignorer ligne vide
                }

                $item = (new ReservationItem())
                    ->setCurrency($code)
                    ->setAmountEuro($eurVal !== null ? number_format($eurVal, 2, '.', '') : null)
                    ->setAmountLocal($locVal !== null ? number_format($locVal, 2, '.', '') : null)
                    ->setRateBuy((float) $rates['buy'])
                    ->setRateSell((float) $rates['sell']);

                $res->addItem($item);
                $added++;
            }

            if ($added === 0) {
                $this->addFlash('error', 'Ajoutez au moins une devise.');
                return $this->redirectToRoute('app_reservation', ['mode' => $mode]);
            }

            $em->persist($res);
            $em->flush();

            return $this->redirectToRoute('app_reservation_recap', ['id' => $res->getId(), 'mode' => $mode]);
        }

        // GET: formulaire + mes réservations
        $auth = $request->getSession()->get('auth_user');
        $userId = $auth['id'] ?? 0;
        $myReservations = $userId ? $reservations->createQueryBuilder('r')
            ->andWhere('r.user = :u')->setParameter('u', $userId)
            ->orderBy('r.createdAt', 'DESC')->getQuery()->getResult()
            : [];

        return $this->render('reservation/index.html.twig', [
            'mode' => $mode,
            'currencies' => $rateService->getSupportedCurrencies(),
            'myReservations' => $myReservations,
        ]);
    }

    #[Route('/reservation/{id}/recap', name: 'app_reservation_recap', methods: ['GET'])]
    public function recap(Reservation $reservation, Request $request): Response
    {
        $mode = $request->query->get('mode', 'guest');
        return $this->render('reservation/recap.html.twig', [
            'mode' => $mode,
            'reservation' => $reservation,
            'now' => new \DateTimeImmutable(),
        ]);
    }

    private static function generateOrderCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private static function todayAt19(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $deadline = (new \DateTimeImmutable($now->format('Y-m-d') . ' 19:00:00'));
        return $deadline;
    }
}
