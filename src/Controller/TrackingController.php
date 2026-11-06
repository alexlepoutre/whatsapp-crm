<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Form\MessageType;
use App\Repository\ConversationRepository;
use App\Service\Inbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        $conversation = $this->find($conversations, $slug, $token);

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

    #[Route('/{slug}/suivi/{token}/cloturer', name: 'tracking_close', requirements: ['slug' => '[a-z0-9][a-z0-9-]*', 'token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function close(string $slug, string $token, Request $request, ConversationRepository $conversations, Inbox $inbox): Response
    {
        $conversation = $this->find($conversations, $slug, $token);

        $this->assertValidToken($request);

        $inbox->closeFromClient($conversation);
        $this->addFlash('success', 'Votre demande est clôturée. Écrivez un nouveau message si vous avez besoin de la rouvrir.');

        return $this->redirectToRoute('tracking_show', ['slug' => $slug, 'token' => $token]);
    }

    #[Route('/{slug}/suivi/{token}/continuer', name: 'tracking_continue', requirements: ['slug' => '[a-z0-9][a-z0-9-]*', 'token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function continue(string $slug, string $token, Request $request, ConversationRepository $conversations, Inbox $inbox): Response
    {
        $conversation = $this->find($conversations, $slug, $token);

        $this->assertValidToken($request);

        $inbox->continueFromClient($conversation);
        $this->addFlash('success', 'Très bien, écrivez votre message ci-dessous.');

        return $this->redirectToRoute('tracking_show', ['slug' => $slug, 'token' => $token]);
    }

    /** Pas d'exception de sécurité ici : ce visiteur sans compte serait renvoyé vers la connexion. */
    private function assertValidToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('tracking-choice', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function find(ConversationRepository $conversations, string $slug, string $token): Conversation
    {
        $conversation = $conversations->findOneBy(['accessToken' => $token]);

        if (null === $conversation || $conversation->getOrganization()->getSlug() !== $slug) {
            throw $this->createNotFoundException();
        }

        return $conversation;
    }
}
