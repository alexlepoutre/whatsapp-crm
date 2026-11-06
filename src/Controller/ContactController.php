<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Service\Inbox;
use App\Service\PhoneNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/', name: 'contact')]
    public function index(
        Request $request,
        Inbox $inbox,
        #[Autowire(service: 'limiter.contact')] RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(WHATSAPP_PUBLIC_NUMBER)%')] string $publicNumber,
    ): Response {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Piège à robots : un humain ne voit pas ce champ. On répond comme si de rien
            // n'était, pour ne pas apprendre au robot ce qui l'a trahi.
            if ($form->get('website')->getData()) {
                return $this->redirectToRoute('contact');
            }

            if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes envoyées depuis votre connexion. Réessayez dans une heure.');

                return $this->redirectToRoute('contact');
            }

            $data = $form->getData();
            $conversation = $inbox->createFromForm($data['name'], $data['email'], $data['phone'], $data['subject'], $data['body']);

            $this->addFlash('success', 'Votre demande est envoyée. Enregistrez cette page : c\'est votre lien de suivi privé.');

            return $this->redirectToRoute('tracking_show', ['token' => $conversation->getAccessToken()]);
        }

        return $this->render('public/contact.html.twig', [
            'form' => $form,
            'whatsappNumber' => PhoneNormalizer::normalize($publicNumber),
        ]);
    }
}
