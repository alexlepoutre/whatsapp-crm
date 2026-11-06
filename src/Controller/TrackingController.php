<?php

namespace App\Controller;

use App\Form\MessageType;
use App\Repository\ConversationRepository;
use App\Service\Inbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi d'une demande sans compte : détenir le lien vaut accès à ce fil-là, et à lui seul.
 */
class TrackingController extends AbstractController
{
    #[Route('/{slug}/suivi/{token}', name: 'tracking_show', requirements: ['slug' => '[a-z0-9][a-z0-9-]*', 'token' => '[a-f0-9]{64}'])]
    public function show(
        string $slug,
        string $token,
        Request $request,
        ConversationRepository $conversations,
        Inbox $inbox,
        #[Autowire(service: 'limiter.tracking_reply')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $conversation = $conversations->findOneBy(['accessToken' => $token]);

        if (null === $conversation || $conversation->getOrganization()->getSlug() !== $slug) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(MessageType::class, null, ['label_body' => 'Votre message']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($limiter->create($token)->consume()->isAccepted()) {
                $inbox->replyFromClient($conversation, $form->get('body')->getData());
                $this->addFlash('success', 'Votre message est envoyé.');
            } else {
                $this->addFlash('error', 'Trop de messages envoyés sur cette demande. Réessayez dans une heure.');
            }

            return $this->redirectToRoute('tracking_show', ['slug' => $slug, 'token' => $token]);
        }

        return $this->render('public/tracking.html.twig', [
            'conversation' => $conversation,
            'form' => $form,
        ]);
    }
}
