<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Form\ContactType;
use App\Service\Inbox;
use App\Service\PhoneNormalizer;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('admin_inbox');
    }

    /** Priorité négative : une adresse de l'application l'emporte toujours sur une organisation. */
    #[Route('/{slug}', name: 'contact', requirements: ['slug' => '[a-z0-9][a-z0-9-]*'], priority: -10)]
    public function index(
        #[MapEntity(mapping: ['slug' => 'slug'])] Organization $organization,
        Request $request,
        Inbox $inbox,
        #[Autowire(service: 'limiter.contact')] RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(WHATSAPP_PUBLIC_NUMBER)%')] string $publicNumber,
    ): Response {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);
        $home = ['slug' => $organization->getSlug()];

        if ($form->isSubmitted() && $form->isValid()) {
            // Piège à robots : un humain ne voit pas ce champ. On répond comme si de rien
            // n'était, pour ne pas apprendre au robot ce qui l'a trahi.
            if ($form->get('website')->getData()) {
                return $this->redirectToRoute('contact', $home);
            }

            if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes envoyées depuis votre connexion. Réessayez dans une heure.');

                return $this->redirectToRoute('contact', $home);
            }

            $data = $form->getData();
            $conversation = $inbox->createFromForm($organization, $data['name'], $data['email'], $data['phone'], $data['subject'], $data['body']);

            $this->addFlash('success', 'Votre demande est envoyée. Enregistrez cette page : c\'est votre lien de suivi privé.');

            return $this->redirectToRoute('tracking_show', $home + ['token' => $conversation->getAccessToken()]);
        }

        return $this->render('public/contact.html.twig', [
            'organization' => $organization,
            'form' => $form,
            'whatsappNumber' => PhoneNormalizer::normalize($publicNumber),
        ]);
    }
}
