<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'login')]
    public function login(AuthenticationUtils $authentication): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_inbox');
        }

        return $this->render('public/login.html.twig', [
            'lastEmail' => $authentication->getLastUsername(),
            'error' => $authentication->getLastAuthenticationError(),
        ]);
    }

    #[Route('/deconnexion', name: 'logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu.');
    }
}
