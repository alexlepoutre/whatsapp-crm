<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\WhatsApp\WebhookProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Meta n'appelle pas cette application : son webhook pointe sur le hook d'immo.alesski.fr,
 * partagé entre plusieurs projets, qui relaie ici les messages destinés à une organisation.
 * Le contenu reste celui de Meta, signé avec un secret partagé avec le hook.
 */
class WebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly OrganizationRepository $organizations,
        #[Autowire('%env(WHATSAPP_RELAY_SECRET)%')] private readonly string $relaySecret,
    ) {
    }

    #[Route('/webhook/relay/{slug}', name: 'webhook_relay', methods: ['POST'])]
    public function relay(string $slug, Request $request): Response
    {
        $content = $request->getContent();

        if (!$this->isSigned($content, (string) $request->headers->get('X-Hub-Signature-256'))) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $organization = $this->organizations->findBySlug($slug);

        // Le hook se sert de ce 404 pour oublier un code qui ne correspond à personne ici.
        if (null === $organization) {
            return new Response('Unknown organization', Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($content, true);

        if (!\is_array($payload)) {
            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $this->processor->process($organization, $payload);

        return new Response('ok');
    }

    /** Sans secret configuré, tout est refusé : l'URL est publique. */
    private function isSigned(string $content, string $signature): bool
    {
        return '' !== $this->relaySecret
            && hash_equals('sha256='.hash_hmac('sha256', $content, $this->relaySecret), $signature);
    }
}
