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
    public function login(Request $request, UserRepository $users): Response
    {
        $mode = $request->query->get('mode', 'guest');

        if ($request->isMethod('POST')) {
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
    public function register(Request $request, EntityManagerInterface $em, UserRepository $users): Response
    {
        $mode = $request->query->get('mode', 'guest');

        if ($request->isMethod('POST')) {
            $firstName = trim((string) $request->request->get('first_name', ''));
            $name      = trim((string) $request->request->get('name', ''));
            $email     = trim((string) $request->request->get('email', ''));
            $dobStr    = trim((string) $request->request->get('dob', ''));
            $password  = (string) $request->request->get('password', '');
            $confirm   = (string) $request->request->get('confirm', '');

            $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $dobStr) ?: null;

            if (!$firstName || !$name || !$email || !$dob || !$password || !$confirm) {
                $this->addFlash('error', 'Veuillez remplir tous les champs, y compris la date de naissance.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
            } elseif ($users->findOneByEmail($email)) {
                $this->addFlash('error', 'Un compte existe déjà avec cet email.');
            } elseif (!$this->isAdult($dob)) {
                $this->addFlash('error', 'Vous ne pouvez pas vous inscrire car vous avez moins de 18 ans.');
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
}

