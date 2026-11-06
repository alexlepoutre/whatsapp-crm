<?php

namespace App\Service;

use App\Entity\Conversation;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Courriels de l'application. Chaque méthode dit si l'envoi a abouti : une panne de
 * messagerie ne doit jamais faire perdre le message déjà enregistré.
 */
class Notifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $from,
        #[Autowire('%env(OWNER_EMAIL)%')] private readonly string $ownerEmail,
    ) {
    }

    public function sendTrackingLink(Conversation $conversation): bool
    {
        return $this->toClient($conversation, 'Votre demande : '.$conversation->getTitle(), 'email/tracking_link.txt.twig', []);
    }

    public function sendReply(Conversation $conversation, string $body): bool
    {
        return $this->toClient($conversation, 'Nouvelle réponse : '.$conversation->getTitle(), 'email/reply.txt.twig', ['body' => $body]);
    }

    public function notifyOwner(Conversation $conversation, string $body): bool
    {
        if ('' === $this->ownerEmail) {
            return false;
        }

        return $this->send(
            (new TemplatedEmail())
                ->to($this->ownerEmail)
                ->subject(\sprintf('[%s] %s', $conversation->getChannel()->label(), $conversation->getClient()->getDisplayName()))
                ->textTemplate('email/owner_notification.txt.twig')
                ->context([
                    'conversation' => $conversation,
                    'body' => $body,
                    'url' => $this->urls->generate('admin_conversation', ['id' => $conversation->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                ]),
        );
    }

    /** @param array<string, mixed> $context */
    private function toClient(Conversation $conversation, string $subject, string $template, array $context): bool
    {
        $email = $conversation->getClient()->getEmail();

        if (null === $email || null === $conversation->getAccessToken()) {
            return false;
        }

        return $this->send(
            (new TemplatedEmail())
                ->to($email)
                ->subject($subject)
                ->textTemplate($template)
                ->context($context + [
                    'conversation' => $conversation,
                    'url' => $this->urls->generate('tracking_show', ['token' => $conversation->getAccessToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                ]),
        );
    }

    private function send(TemplatedEmail $email): bool
    {
        try {
            $this->mailer->send($email->from(Address::create($this->from)));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Courriel non envoyé : '.$e->getMessage());

            return false;
        }
    }
}
