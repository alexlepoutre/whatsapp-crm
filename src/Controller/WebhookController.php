<?php

namespace App\Controller;

use App\WhatsApp\WebhookProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée de Meta. Ni session ni CSRF ici : c'est la signature qui authentifie.
 */
class WebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        #[Autowire('%env(WHATSAPP_VERIFY_TOKEN)%')] private readonly string $verifyToken,
        #[Autowire('%env(WHATSAPP_APP_SECRET)%')] private readonly string $appSecret,
    ) {
    }

    /** PHP transforme les « hub.mode » de Meta en « hub_mode ». */
    #[Route('/webhook/whatsapp', name: 'webhook_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $token = (string) $request->query->get('hub_verify_token');

        if ('' === $this->verifyToken
            || 'subscribe' !== $request->query->get('hub_mode')
            || !hash_equals($this->verifyToken, $token)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return new Response((string) $request->query->get('hub_challenge'), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    #[Route('/webhook/whatsapp', name: 'webhook_receive', methods: ['POST'])]
    public function receive(Request $request): Response
    {
        $content = $request->getContent();

        if (!$this->isSignedByMeta($content, (string) $request->headers->get('X-Hub-Signature-256'))) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($content, true);

        if (!\is_array($payload)) {
            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $this->processor->process($payload);

        return new Response('ok');
    }

    /** Sans secret configuré, tout est refusé : l'URL du webhook est publique. */
    private function isSignedByMeta(string $content, string $signature): bool
    {
        return '' !== $this->appSecret
            && hash_equals('sha256='.hash_hmac('sha256', $content, $this->appSecret), $signature);
    }
}
