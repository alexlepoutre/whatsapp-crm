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
        try {
            $response = $this->httpClient->request(
                'POST',
                \sprintf('https://graph.facebook.com/%s/%s/messages', $this->apiVersion, $this->phoneNumberId),
                [
                    'auth_bearer' => $this->accessToken,
                    'timeout' => 10,
                    'json' => [
                        'messaging_product' => 'whatsapp',
                        'to' => $to,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ],
                ],
            );

            $data = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new WhatsAppException('Meta injoignable : '.$e->getMessage(), 0, $e);
        }

        $id = $data['messages'][0]['id'] ?? null;

        if ($status >= 400 || !\is_string($id)) {
            throw new WhatsAppException($data['error']['message'] ?? \sprintf('Réponse Meta inattendue (HTTP %d).', $status));
        }

        return $id;
    }
}
