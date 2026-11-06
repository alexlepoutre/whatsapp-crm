<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Organization;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
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
        private readonly OrganizationTransport $transports,
        private readonly BodyRendererInterface $bodyRenderer,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $from,
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
        $recipient = $conversation->getOrganization()->getNotificationEmail();

        if (null === $recipient) {
            return false;
        }

        return $this->send(
            $conversation->getOrganization(),
            (new TemplatedEmail())
                ->to($recipient)
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
            $conversation->getOrganization(),
            (new TemplatedEmail())
                ->to($email)
                ->subject($subject)
                ->textTemplate($template)
                ->context($context + [
                    'conversation' => $conversation,
                    'url' => $this->urls->generate('tracking_show', ['slug' => $conversation->getOrganization()->getSlug(), 'token' => $conversation->getAccessToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                ]),
        );
    }

    /** @return string|null le motif de l'échec, null si le courriel est parti */
    public function sendTest(Organization $organization, string $recipient): ?string
    {
        $transport = $this->transports->for($organization);

        if (null === $transport) {
            return 'Le mot de passe est manquant ou illisible, ressaisissez-le.';
        }

        $email = (new Email())
            ->from(new Address((string) $organization->getSmtpUsername(), $organization->getName()))
            ->to($recipient)
            ->subject('Courriel de test : '.$organization->getName())
            ->text('La messagerie de '.$organization->getName().' fonctionne : ce courriel est parti de son serveur SMTP.');

        try {
            $transport->send($email);

            return null;
        } catch (TransportExceptionInterface $e) {
            return $e->getMessage();
        }
    }

    private function send(Organization $organization, TemplatedEmail $email): bool
    {
        try {
            $transport = $this->transports->for($organization);

            if (null === $transport) {
                $this->mailer->send($email->from(Address::create($this->from)));

                return true;
            }

            // Ce transport est hors du Mailer : le gabarit doit être rendu ici.
            $email->from(new Address((string) $organization->getSmtpUsername(), $organization->getName()));
            $this->bodyRenderer->render($email);
            $transport->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Courriel non envoyé : '.$e->getMessage(), ['organization' => $organization->getSlug()]);

            return false;
        }
    }
}
