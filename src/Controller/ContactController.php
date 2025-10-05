<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET','POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserRepository $users, \App\Service\Csrf $csrf): Response
    {
        $mode = $request->query->get('mode', 'guest');

        if ($request->isMethod('POST')) {
            if (!$csrf->isValid('contact', (string)$request->request->get('_csrf'))) {
                $this->addFlash('error', 'Requête invalide (CSRF).');
                return $this->redirectToRoute('app_contact', ['mode' => $mode]);
            }
            if ($mode === 'guest') {
                $this->addFlash('error', 'Connectez-vous pour envoyer un message.');
                return $this->redirectToRoute('app_contact', ['mode' => $mode]);
            }

            $auth = $request->getSession()->get('auth_user');
            $user = $auth ? $users->find($auth['id'] ?? 0) : null;

            $name = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
            $message = trim((string) $request->request->get('message', ''));

            if ($name && $email && $message) {
                $msg = (new ContactMessage())
                    ->setUser($user)
                    ->setName($name)
                    ->setEmail($email)
                    ->setMessage($message);
                $em->persist($msg);
                $em->flush();

                $this->addFlash('success', 'Message envoyé à l’administrateur.');
                return $this->redirectToRoute('app_contact', ['mode' => $mode]);
            }

            $this->addFlash('error', 'Veuillez compléter tous les champs.');
        }

        return $this->render('contact/index.html.twig', ['mode' => $mode]);
    }
}

