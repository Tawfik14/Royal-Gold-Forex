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
    // Horaires d'ouverture (lundi → samedi uniquement)
    private const OPEN_HOUR  = 9;
    private const OPEN_MIN   = 30;
    private const CLOSE_HOUR = 19;
    private const CLOSE_MIN  = 0;

    // Timezones
    private const TZ_PARIS = 'Europe/Paris';
    private const TZ_UTC   = 'UTC';

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

        // Heure locale Paris pour toutes les décisions métier
        $tzParis = new \DateTimeZone(self::TZ_PARIS);
        $nowParis = new \DateTimeImmutable('now', $tzParis);

        if ($request->isMethod('POST')) {
            // Fermé ? (dimanche ou hors créneau)
            if (!self::isOpenAt($nowParis)) {
                $isSunday = $nowParis->format('N') === '7';
                if ($isSunday) {
                    $this->addFlash('error', 'Les réservations sont fermées le dimanche. Réessayez lundi à partir de 09:30 (heure de Paris).');
                } else {
                    $this->addFlash('error', 'Les réservations sont ouvertes du lundi au samedi de 09:30 à 19:00 (heure de Paris).');
                }
                return $this->redirectToRoute('app_reservation', ['mode' => $mode]);
            }

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

            // >>>> Normalisation des noms/prénoms <<<<
            $firstName = self::normalizeFirstName($firstName); // Prénom : 1ère lettre majuscule seulement
            $lastName  = self::normalizeLastName($lastName);   // Nom : tout en MAJUSCULE

            // Deadline du jour à 19:00 Europe/Paris (on ne peut pas réserver le dimanche donc jamais de deadline un dimanche)
            $deadlineParis = self::todayAt19($nowParis);
            // Conversion en UTC avant persistance
            $deadlineUtc = $deadlineParis->setTimezone(new \DateTimeZone(self::TZ_UTC));

            $res = (new Reservation())
                ->setUser($user)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setOperation($operation)
                ->setOrderCode(self::generateOrderCode())
                ->setPickupDeadline($deadlineUtc); // stocké en UTC

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

        $isOpen = self::isOpenAt($nowParis);
        $isSunday = $nowParis->format('N') === '7';

        return $this->render('reservation/index.html.twig', [
            'mode'           => $mode,
            'currencies'     => $rateService->getSupportedCurrencies(),
            'myReservations' => $myReservations,
            'now'            => $nowParis,
            'isOpen'         => $isOpen,
            'isSunday'       => $isSunday,
            'openLabel'      => '09:30',
            'closeLabel'     => '19:00',
            'openDaysLabel'  => 'lundi au samedi',
            // Affichage heure locale Paris dans Twig :
            // {{ reservation.pickupDeadline|date('d/m/Y H:i', 'Europe/Paris') }}
        ]);
    }

    #[Route('/reservation/{id}/recap', name: 'app_reservation_recap', methods: ['GET'])]
    public function recap(Reservation $reservation, Request $request): Response
    {
        $mode = $request->query->get('mode', 'guest');

        // now en Europe/Paris (utile si le template compare avec "now")
        $nowParis = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_PARIS));

        // Calcul du temps restant (deadline stockée en UTC → reconvertie Paris pour le diff)
        $remaining = self::timeRemaining($nowParis, $reservation->getPickupDeadline());

        return $this->render('reservation/recap.html.twig', [
            'mode'        => $mode,
            'reservation' => $reservation,
            'now'         => $nowParis,
            'remaining'   => $remaining,
            // Affichage recommandé côté Twig :
            // {{ reservation.pickupDeadline|date('d/m/Y H:i', 'Europe/Paris') }}
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

    /** Fenêtre d'ouverture 09:30–19:00 à Paris, fermé le dimanche */
    private static function isOpenAt(\DateTimeImmutable $whenParis): bool
    {
        // Dimanche fermé
        if ($whenParis->format('N') === '7') {
            return false;
        }

        $open  = $whenParis->setTime(self::OPEN_HOUR, self::OPEN_MIN, 0);
        $close = $whenParis->setTime(self::CLOSE_HOUR, self::CLOSE_MIN, 0);
        return $whenParis >= $open && $whenParis < $close;
    }

    /**
     * Échéance à 19:00 Europe/Paris.
     * - Si l'heure actuelle >= 19:00, alors demain 19:00.
     * (Pas de cas dimanche car on ne peut pas réserver ce jour-là.)
     */
    private static function todayAt19(\DateTimeImmutable $nowParis = null): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TZ_PARIS);
        $nowParis = $nowParis ?? new \DateTimeImmutable('now', $tz);
        $today19  = $nowParis->setTime(19, 0, 0);

        return ($nowParis >= $today19) ? $today19->modify('+1 day') : $today19;
    }

    /**
     * Calcule le temps restant entre "maintenant Paris" et la deadline (stockée en UTC).
     * Retourne ['hours' => int, 'minutes' => int, 'isPast' => bool]
     * - Calcul en minutes totales pour garantir minutes < 60 (conversion H+M propre).
     */
    private static function timeRemaining(\DateTimeImmutable $nowParis, \DateTimeImmutable $deadlineUtc): array
    {
        $deadlineParis = $deadlineUtc->setTimezone(new \DateTimeZone(self::TZ_PARIS));

        // Déjà dépassé ?
        if ($nowParis >= $deadlineParis) {
            return ['hours' => 0, 'minutes' => 0, 'isPast' => true];
        }

        // Différence en minutes totales
        $diffMinutes = (int) floor(($deadlineParis->getTimestamp() - $nowParis->getTimestamp()) / 60);

        $hours   = intdiv($diffMinutes, 60);
        $minutes = $diffMinutes % 60;

        return ['hours' => $hours, 'minutes' => $minutes, 'isPast' => false];
    }

    /**
     * Normalise le prénom : 1ère lettre majuscule, reste en minuscule.
     * Gère les accents via mb_*.
     */
    private static function normalizeFirstName(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return $s;

        $lower = mb_strtolower($s, 'UTF-8');
        $first = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8');

        return $first . mb_substr($lower, 1, null, 'UTF-8');
    }

    /**
     * Normalise le nom : tout en MAJUSCULES.
     */
    private static function normalizeLastName(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_strtoupper($s, 'UTF-8');
    }
}

