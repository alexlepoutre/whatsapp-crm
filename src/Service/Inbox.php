<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Enum\Channel;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use App\Repository\ClientRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Point de passage de tous les messages, entrants comme sortants, quel que soit le canal.
 */
class Inbox
{
    /** Au-delà de ce silence, un message est une nouvelle sollicitation : le bot répond de nouveau. */
    private const QUIET_PERIOD = '-12 hours';

    private const OPENED = 'Conversation ouverte depuis la page de contact.';
    private const OPENED_REPLY = 'Bonjour, nous sommes bien en contact. Posez maintenant votre question ici, nous vous répondons dès que possible.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clients,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly WhatsAppClient $whatsApp,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Meta renvoie un webhook tant qu'il n'a pas reçu de 200. Si deux envois se croisent,
     * la contrainte d'unicité fait échouer le second : Meta réessaie et tombe sur le test
     * ci-dessous. L'exception n'est donc volontairement pas interceptée.
     */
    public function receiveWhatsApp(Organization $organization, string $phone, ?string $profileName, string $waMessageId, string $body, ?string $transcription = null): void
    {
        if (null !== $this->messages->findByWaMessageId($waMessageId)) {
            return;
        }

        $client = $this->clients->findByPhone($organization, $phone);

        if (null === $client) {
            $client = (new Client($organization))->setPhone($phone);
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

        // La vraie demande suit la phrase d'ouverture : c'est elle qui doit déclencher l'alerte.
        $isNewSolicitation = $conversation->isQuietSince(new \DateTimeImmutable(self::QUIET_PERIOD))
            || self::OPENED === $conversation->getLastClientMessage()?->getBody();

        $body = $this->withoutWhatsAppOpening($organization, $body);
        (new Message($conversation, MessageSender::CLIENT, $body))->setWaMessageId($waMessageId);
        $this->entityManager->flush();

        // Le client doit pouvoir vérifier ce qui a été compris, même bot désactivé.
        if (null !== $transcription) {
            $this->sendAutomatic($conversation, "🎤 Voici ce que nous avons compris de votre message vocal :\n\n« ".$transcription.' »');
        }

        // La phrase d'ouverture ne contient aucune demande : l'équipe n'a rien à lire à ce stade.
        if (self::OPENED === $body) {
            $this->sendBotReply($conversation, self::OPENED_REPLY);

            return;
        }

        $this->notifier->notifyTeam($conversation, $body);

        if ($isNewSolicitation) {
            $this->sendBotReply($conversation, $organization->getBotMessage());
        }
    }

    /**
     * La phrase préparée par le bouton WhatsApp sert au routage, ce n'est pas la demande du
     * client : on la retire et on ne garde que ce qu'il a écrit lui-même.
     */
    private function withoutWhatsAppOpening(Organization $organization, string $body): string
    {
        $code = preg_quote($organization->getWhatsAppCode(), '/');

        $rest = str_ireplace($organization->getWhatsAppOpening(), '', $body, $count);

        if (0 === $count) {
            $rest = preg_replace('/\(\s*'.$code.'\s*\)|^\s*'.$code.'(?![\w-])/iu', '', $body, 1, $count);
        }

        if (0 === $count) {
            return $body;
        }

        $rest = trim((string) $rest, " \t\n\r:,.-");

        return '' === $rest ? self::OPENED : $rest;
    }

    public function createFromForm(Organization $organization, string $name, string $email, ?string $phone, string $subject, string $body): Conversation
    {
        $client = $this->clients->findByEmail($organization, $email) ?? $this->clients->findByPhone($organization, $phone);

        if (null === $client) {
            $client = new Client($organization);
            $this->entityManager->persist($client);
        }

        // On complète sans jamais écraser : ces données sont déclaratives, personne ne les a
        // vérifiées, et elles ne doivent pas pouvoir réécrire la fiche d'un client existant.
        $client->setName($client->getName() ?? $name);

        if (null === $client->getEmail() && null === $this->clients->findByEmail($organization, $email)) {
            $client->setEmail($email);
        }

        if (null === $client->getPhone() && null === $this->clients->findByPhone($organization, $phone)) {
            $client->setPhone($phone);
        }

        $conversation = new Conversation($client, Channel::FORM, $subject);
        $this->entityManager->persist($conversation);
        new Message($conversation, MessageSender::CLIENT, $body);
        $this->entityManager->flush();

        $this->notifier->sendTrackingLink($conversation);
        $this->notifier->notifyTeam($conversation, $body);

        return $conversation;
    }

    public function replyFromClient(Conversation $conversation, string $body): void
    {
        new Message($conversation, MessageSender::CLIENT, $body);
        $this->entityManager->flush();

        $this->notifier->notifyTeam($conversation, $body);
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

    /**
     * Quelqu'un vient d'ouvrir la conversation. Une panne laisse les messages non lus : le prochain
     * affichage réessaiera, et ne pas passer au bleu ne doit jamais empêcher de lire la page.
     */
    public function markAsRead(Conversation $conversation): void
    {
        if (!$conversation->isWhatsApp() || !$this->whatsApp->isConfigured()) {
            return;
        }

        $unread = $conversation->getMessages()->filter(
            static fn (Message $m) => $m->isFromClient() && null !== $m->getWaMessageId() && null === $m->getReadAt(),
        );
        $last = $unread->last();

        if (false === $last) {
            return;
        }

        try {
            $this->whatsApp->markAsRead((string) $last->getWaMessageId());
        } catch (WhatsAppException $e) {
            $this->logger->error('Accusé de lecture refusé : '.$e->getMessage(), ['conversation' => $conversation->getId()]);

            return;
        }

        foreach ($unread as $message) {
            $message->markRead();
        }

        $this->entityManager->flush();
    }

    private function sendBotReply(Conversation $conversation, string $text): void
    {
        if (!$conversation->getOrganization()->isBotEnabled()) {
            return;
        }

        $this->sendAutomatic($conversation, $text);
    }

    private function sendAutomatic(Conversation $conversation, string $text): void
    {
        $message = new Message($conversation, MessageSender::BOT, $text);
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
