<?php

namespace App\WhatsApp;

use App\Entity\Organization;
use App\Enum\DeliveryStatus;
use App\Repository\MessageRepository;
use App\Service\Inbox;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traduit le contenu d'un webhook Meta en actions sur la boîte de réception.
 */
class WebhookProcessor
{
    private const MEDIA_LABELS = [
        'image' => 'Photo',
        'video' => 'Vidéo',
        'document' => 'Document',
        'sticker' => 'Sticker',
        'location' => 'Position',
        'contacts' => 'Contact',
    ];

    public function __construct(
        private readonly Inbox $inbox,
        private readonly MessageRepository $messages,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function process(Organization $organization, array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = $this->profileNames($value['contacts'] ?? []);

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleMessage($organization, $message, $names);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatus($organization, $status);
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     *
     * @return array<string, string>
     */
    private function profileNames(array $contacts): array
    {
        $names = [];

        foreach ($contacts as $contact) {
            if (isset($contact['wa_id'], $contact['profile']['name'])) {
                $names[$contact['wa_id']] = $contact['profile']['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $names
     */
    private function handleMessage(Organization $organization, array $message, array $names): void
    {
        $from = $message['from'] ?? null;
        $id = $message['id'] ?? null;

        if (!\is_string($from) || !\is_string($id)) {
            return;
        }

        $this->inbox->receiveWhatsApp($organization, $from, $names[$from] ?? null, $id, $this->bodyOf($message));
    }

    /**
     * Rien n'est écarté : un message qu'on ne sait pas afficher reste signalé, sinon
     * un client qui envoie un vocal n'obtiendrait jamais de réponse.
     *
     * @param array<string, mixed> $message
     */
    private function bodyOf(array $message): string
    {
        $type = $message['type'] ?? 'unknown';

        if ('text' === $type) {
            return (string) ($message['text']['body'] ?? '');
        }

        if ('button' === $type) {
            return (string) ($message['button']['text'] ?? '');
        }

        if ('interactive' === $type) {
            $reply = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? [];

            return (string) ($reply['title'] ?? '');
        }

        if ('audio' === $type || 'voice' === $type) {
            $transcription = trim((string) ($message['transcription'] ?? ''));

            return '' === $transcription
                ? '[Message vocal reçu, transcription indisponible]'
                : "[Message vocal, transcription automatique]\n".$transcription;
        }

        $label = self::MEDIA_LABELS[$type] ?? 'Message d\'un type non géré';
        $caption = $message[$type]['caption'] ?? null;

        return \sprintf('[%s reçu(e), affichage non disponible]', $label).($caption ? "\n".$caption : '');
    }

    /** @param array<string, mixed> $status */
    private function handleStatus(Organization $organization, array $status): void
    {
        $delivery = DeliveryStatus::tryFrom((string) ($status['status'] ?? ''));
        $message = isset($status['id']) ? $this->messages->findByWaMessageId((string) $status['id']) : null;

        if (null === $delivery || null === $message || $message->getConversation()->getOrganization() !== $organization) {
            return;
        }

        $error = $status['errors'][0] ?? [];
        $message->advanceDelivery($delivery, $error['message'] ?? $error['title'] ?? null);
        $this->entityManager->flush();
    }
}
