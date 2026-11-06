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
    public const CHOICE_CLOSE = 'crm_close';
    public const CHOICE_CONTINUE = 'crm_continue';

    /** Limite de Meta pour le texte d'un message à boutons. */
    private const CHOICE_BODY_LIMIT = 1024;
    private const CHOICE_PROMPT = 'Votre demande est-elle traitée ?';

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

        return $this->messageId($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws WhatsAppException
     */
    private function messageId(array $data): string
    {
        $id = $data['messages'][0]['id'] ?? null;

        if (!\is_string($id)) {
            throw new WhatsAppException('Réponse Meta sans identifiant de message.');
        }

        return $id;
    }

    /**
     * Une réponse de l'équipe, suivie du choix « Clôturer » ou « Continuer ».
     *
     * @return string identifiant Meta de la réponse
     *
     * @throws WhatsAppException
     */
    public function sendTextWithChoice(string $to, string $body): string
    {
        if (mb_strlen($body) <= self::CHOICE_BODY_LIMIT) {
            return $this->sendChoice($to, $body);
        }

        $id = $this->sendText($to, $body);

        // La réponse est partie : l'absence des boutons ne doit pas la faire passer pour un échec.
        try {
            $this->sendChoice($to, self::CHOICE_PROMPT);
        } catch (WhatsAppException) {
        }

        return $id;
    }

    /** @throws WhatsAppException */
    private function sendChoice(string $to, string $body): string
    {
        $data = $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => self::CHOICE_CLOSE, 'title' => 'Clôturer']],
                    ['type' => 'reply', 'reply' => ['id' => self::CHOICE_CONTINUE, 'title' => 'Continuer']],
                ]],
            ],
        ]);

        return $this->messageId($data);
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
