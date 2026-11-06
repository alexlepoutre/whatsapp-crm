<?php

namespace App\WhatsApp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi par l'API WhatsApp Cloud de Meta.
 */
class WhatsAppClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')] private readonly string $accessToken,
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')] private readonly string $phoneNumberId,
        #[Autowire('%env(WHATSAPP_API_VERSION)%')] private readonly string $apiVersion,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->accessToken && '' !== $this->phoneNumberId;
    }

    /**
     * @return string identifiant Meta du message envoyé
     *
     * @throws WhatsAppException
     */
    public function sendText(string $to, string $body): string
    {
        $data = $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);

        $id = $data['messages'][0]['id'] ?? null;

        if (!\is_string($id)) {
            throw new WhatsAppException('Réponse Meta sans identifiant de message.');
        }

        return $id;
    }

    /**
     * Passe au bleu les coches du client, pour ce message et tous les précédents.
     *
     * @throws WhatsAppException
     */
    public function markAsRead(string $messageId): void
    {
        $this->post([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     *
     * @throws WhatsAppException
     */
    private function post(array $json): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                \sprintf('https://graph.facebook.com/%s/%s/messages', $this->apiVersion, $this->phoneNumberId),
                ['auth_bearer' => $this->accessToken, 'timeout' => 10, 'json' => $json],
            );

            $data = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new WhatsAppException('Meta injoignable : '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            throw new WhatsAppException($data['error']['message'] ?? \sprintf('Réponse Meta inattendue (HTTP %d).', $status));
        }

        return $data;
    }
}
