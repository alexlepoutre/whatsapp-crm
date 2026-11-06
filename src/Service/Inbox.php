<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\Channel;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use App\Repository\ClientRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\SettingsRepository;
use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point de passage de tous les messages, entrants comme sortants, quel que soit le canal.
 */
class Inbox
{
    /** Au-delà de ce silence, un message est une nouvelle sollicitation : bot et alerte repartent. */
    private const QUIET_PERIOD = '-12 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clients,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly SettingsRepository $settings,
        private readonly WhatsAppClient $whatsApp,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Meta renvoie un webhook tant qu'il n'a pas reçu de 200. Si deux envois se croisent,
     * la contrainte d'unicité fait échouer le second : Meta réessaie et tombe sur le test
     * ci-dessous. L'exception n'est donc volontairement pas interceptée.
     */
    public function receiveWhatsApp(string $phone, ?string $profileName, string $waMessageId, string $body): void
    {
        if (null !== $this->messages->findByWaMessageId($waMessageId)) {
            return;
        }

        $client = $this->clients->findByPhone($phone);

        if (null === $client) {
            $client = (new Client())->setPhone($phone);
            $this->entityManager->persist($client);
        }

        if (null === $client->getName() && $profileName) {
            $client->setName(mb_substr($profileName, 0, 120));
        }

        $conversation = $client->getId() ? $this->conversations->findWhatsAppFor($client) : null;

        if (null === $conversation) {
            $conversation = new Conversation($client, Channel::WHATSAPP);
            $this->entityManager->persist($conversation);
        }

        $isNewSolicitation = $conversation->isQuietSince(new \DateTimeImmutable(self::QUIET_PERIOD));

        (new Message($conversation, MessageSender::CLIENT, $body))->setWaMessageId($waMessageId);
        $this->entityManager->flush();

        if ($isNewSolicitation) {
            $this->notifier->notifyOwner($conversation, $body);
            $this->sendBotReply($conversation);
        }
    }

    public function createFromForm(string $name, string $email, ?string $phone, string $subject, string $body): Conversation
    {
        $client = $this->clients->findByEmail($email) ?? $this->clients->findByPhone($phone);

        if (null === $client) {
            $client = new Client();
            $this->entityManager->persist($client);
        }

        // On complète sans jamais écraser : ces données sont déclaratives, personne ne les a
        // vérifiées, et elles ne doivent pas pouvoir réécrire la fiche d'un client existant.
        $client->setName($client->getName() ?? $name);

        if (null === $client->getEmail() && null === $this->clients->findByEmail($email)) {
            $client->setEmail($email);
        }

        if (null === $client->getPhone() && null === $this->clients->findByPhone($phone)) {
            $client->setPhone($phone);
        }

        $conversation = new Conversation($client, Channel::FORM, $subject);
        $this->entityManager->persist($conversation);
        new Message($conversation, MessageSender::CLIENT, $body);
        $this->entityManager->flush();

        $this->notifier->sendTrackingLink($conversation);
        $this->notifier->notifyOwner($conversation, $body);

        return $conversation;
    }

    public function replyFromClient(Conversation $conversation, string $body): void
    {
        new Message($conversation, MessageSender::CLIENT, $body);
        $this->entityManager->flush();

        $this->notifier->notifyOwner($conversation, $body);
    }

    /** Le message est enregistré avant l'envoi : un échec reste visible et n'efface rien. */
    public function replyFromOwner(Conversation $conversation, string $body): Message
    {
        $message = new Message($conversation, MessageSender::OWNER, $body);
        $this->entityManager->flush();

        if ($conversation->isWhatsApp()) {
            $this->deliverOnWhatsApp($message);
        } elseif ($this->notifier->sendReply($conversation, $body)) {
            $message->markDelivery(DeliveryStatus::SENT);
        } else {
            $message->markDelivery(DeliveryStatus::FAILED, 'Le courriel n\'a pas pu partir.');
        }

        $this->entityManager->flush();

        return $message;
    }

    private function sendBotReply(Conversation $conversation): void
    {
        $settings = $this->settings->current();

        if (!$settings->isBotEnabled()) {
            return;
        }

        $message = new Message($conversation, MessageSender::BOT, $settings->getBotMessage());
        $this->entityManager->flush();

        $this->deliverOnWhatsApp($message);
        $this->entityManager->flush();
    }

    private function deliverOnWhatsApp(Message $message): void
    {
        if (!$this->whatsApp->isConfigured()) {
            $message->markDelivery(DeliveryStatus::SIMULATED);

            return;
        }

        try {
            $id = $this->whatsApp->sendText((string) $message->getConversation()->getClient()->getPhone(), $message->getBody());
            $message->setWaMessageId($id);
            $message->markDelivery(DeliveryStatus::SENT);
        } catch (WhatsAppException $e) {
            $message->markDelivery(DeliveryStatus::FAILED, $e->getMessage());
        }
    }
}
