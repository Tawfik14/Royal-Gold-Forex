<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET','POST'])]
    public function login(Request $request, UserRepository $users, \App\Service\Csrf $csrf): Response
    {
        $mode = $request->query->get('mode', 'guest');

        if ($request->isMethod('POST')) {
            if (!$csrf->isValid('login', (string)$request->request->get('_csrf'))) {
                $this->addFlash('error', 'Requête invalide (CSRF).');
                return $this->redirectToRoute('app_login', ['mode' => $mode]);
            }
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $user = $users->findOneByEmail($email);
            if ($user && password_verify($password, $user->getPasswordHash())) {
                $request->getSession()->set('auth_user', [
                    'id'        => $user->getId(),
                    'firstName' => $user->getFirstName(),
                    'name'      => $user->getName(),
                    'email'     => $user->getEmail(),
                    'role'      => $user->getRole(),
                ]);
                $redirMode = $user->getRole() === 'admin' ? 'admin' : 'user';
                return $this->redirectToRoute('app_exchange', ['mode' => $redirMode]);
            }
            $this->addFlash('error', 'Identifiants invalides.');
        }

        return $this->render('auth/login.html.twig', ['mode' => $mode]);
    }

    #[Route('/inscription', name: 'app_register', methods: ['GET','POST'])]
    public function register(Request $request, EntityManagerInterface $em, UserRepository $users, \App\Service\Csrf $csrf): Response
    {
        $mode = $request->query->get('mode', 'guest');

        if ($request->isMethod('POST')) {
            if (!$csrf->isValid('register', (string)$request->request->get('_csrf'))) {
                $this->addFlash('error', 'Requête invalide (CSRF).');
                return $this->redirectToRoute('app_register', ['mode' => $mode]);
            }
            $firstName = trim((string) $request->request->get('first_name', ''));
            $name      = trim((string) $request->request->get('name', ''));
            $email     = trim((string) $request->request->get('email', ''));
            $dobStr    = trim((string) $request->request->get('dob', ''));
            $password  = (string) $request->request->get('password', '');
            $confirm   = (string) $request->request->get('confirm', '');

            // Normalisation prénom/nom
            $firstName = self::normalizeFirstName($firstName);
            $name      = self::normalizeLastName($name);

            $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $dobStr) ?: null;

            $reasons = [];
            if (!$firstName || !$name || !$email || !$dob || !$password || !$confirm) {
                $this->addFlash('error', 'Veuillez remplir tous les champs, y compris la date de naissance.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
            } elseif ($users->findOneByEmail($email)) {
                $this->addFlash('error', 'Un compte existe déjà avec cet email.');
            } elseif (!$this->isAdult($dob)) {
                $this->addFlash('error', 'Vous ne pouvez pas vous inscrire car vous avez moins de 18 ans.');
            } elseif (!$this->isStrongPassword($password, $reasons)) {
                $this->addFlash('error', 'Mot de passe trop faible : ' . implode(', ', $reasons));
            } else {
                $u = (new User())
                    ->setFirstName($firstName)
                    ->setName($name)
                    ->setEmail($email)
                    ->setDateOfBirth($dob)
                    ->setPasswordHash(password_hash($password, PASSWORD_DEFAULT))
                    ->setRole(in_array(strtolower($email), ['admin@example.com','admin@exchange.local'], true) ? 'admin' : 'user');

                $em->persist($u);
                $em->flush();

                $request->getSession()->set('auth_user', [
                    'id'        => $u->getId(),
                    'firstName' => $u->getFirstName(),
                    'name'      => $u->getName(),
                    'email'     => $u->getEmail(),
                    'role'      => $u->getRole(),
                ]);
                $redirMode = $u->getRole() === 'admin' ? 'admin' : 'user';
                return $this->redirectToRoute('app_exchange', ['mode' => $redirMode]);
            }
        }

        return $this->render('auth/register.html.twig', ['mode' => $mode]);
    }

    private function isAdult(\DateTimeImmutable $dob): bool
    {
        $today = new \DateTimeImmutable('today');
        $age = $dob->diff($today)->y;
        return $age >= 18;
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('auth_user');
        return $this->redirectToRoute('app_exchange', ['mode' => 'guest']);
    }

    
    private static function normalizeFirstName(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return $s;

        $lower = mb_strtolower($s, 'UTF-8');
        $first = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8');

        return $first . mb_substr($lower, 1, null, 'UTF-8');
    }

    /** Normalise nom : tout en majuscules */
    private static function normalizeLastName(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_strtoupper($s, 'UTF-8');
    }

    /**
     * Vérifie la robustesse du mot de passe.
     * Retourne toutes les erreurs dans $reasons.
     */
    private function isStrongPassword(string $pwd, array &$reasons): bool
    {
        $reasons = [];

        if (strlen($pwd) < 10) {
            $reasons[] = 'au moins 10 caractères';
        }
        if (preg_match('/\s/', $pwd)) {
            $reasons[] = 'aucun espace';
        }
        if (!preg_match('/[a-z]/', $pwd)) {
            $reasons[] = 'ajoutez une lettre minuscule';
        }
        if (!preg_match('/[A-Z]/', $pwd)) {
            $reasons[] = 'ajoutez une lettre majuscule';
        }
        if (!preg_match('/\d/', $pwd)) {
            $reasons[] = 'ajoutez un chiffre';
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $pwd)) {
            $reasons[] = 'ajoutez un caractère spécial';
        }

        // Liste de mots de passe interdits
        $common = [
            'password','123456','123456789','qwerty','azerty','111111','123123','abc123',
            'iloveyou','admin','welcome','monmotdepasse','motdepasse','passw0rd','000000',
        ];
        if (in_array(strtolower($pwd), $common, true)) {
            $reasons[] = 'il est trop courant';
        }

        return empty($reasons);
    }
}

