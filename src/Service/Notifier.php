<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\MailSettingsRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Courriels de l'application. Chaque méthode dit si l'envoi a abouti : une panne de
 * messagerie ne doit jamais faire perdre le message déjà enregistré.
 */
class Notifier implements EventSubscriberInterface
{
    /** @var array<int, array{Conversation, string}> */
    private array $pending = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly OrganizationTransport $transports,
        private readonly MessageCatalog $messages,
        private readonly UserRepository $users,
        private readonly MailSettingsRepository $mailSettings,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $from,
    ) {
    }

    public function sendTrackingLink(Conversation $conversation): bool
    {
        return $this->toClient($conversation, 'Votre demande : '.$conversation->getTitle(), MessageCatalog::TRACKING_LINK);
    }

    public function sendReply(Conversation $conversation, string $body, bool $final = false): bool
    {
        return $this->toClient($conversation, 'Nouvelle réponse : '.$conversation->getTitle(), $final ? MessageCatalog::FINAL_REPLY : MessageCatalog::REPLY, ['message' => $body]);
    }

    /**
     * Prévient l'équipe, après la réponse à la requête : un envoi peut durer plus de 10 secondes.
     * Le client n'a pas à attendre, et le relais WhatsApp expirerait.
     */
    public function notifyTeam(Conversation $conversation, string $body): void
    {
        $this->pending[] = [$conversation, $body];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'sendPending',
            ConsoleEvents::TERMINATE => 'sendPending',
        ];
    }

    public function sendPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$conversation, $body]) {
            $this->sendToTeam($conversation, $body);
        }
    }

    private function sendToTeam(Conversation $conversation, string $body): void
    {
        $organization = $conversation->getOrganization();
        $recipients = $this->teamAddresses($organization);

        if ([] === $recipients) {
            return;
        }

        $this->send(
            $organization,
            (new Email())
                ->to(...$recipients)
                ->subject(\sprintf('[%s] %s', $conversation->getChannel()->label(), $conversation->getClient()->getDisplayName()))
                ->text($this->messages->render($organization, MessageCatalog::TEAM, [
                    'client' => $conversation->getClient()->getDisplayName(),
                    'canal' => $conversation->getChannel()->label(),
                    'message' => $body,
                    'lien' => $this->urls->generate('admin_conversation', ['id' => $conversation->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                ])),
        );
    }

    /** @return string[] */
    private function teamAddresses(Organization $organization): array
    {
        $addresses = array_map(static fn (User $user) => $user->getEmail(), $this->users->findBy(['organization' => $organization]));

        if (null !== $organization->getNotificationEmail()) {
            $addresses[] = mb_strtolower($organization->getNotificationEmail());
        }

        return array_values(array_unique($addresses));
    }

    /** @param array<string, string> $variables */
    private function toClient(Conversation $conversation, string $subject, string $messageKey, array $variables = []): bool
    {
        $email = $conversation->getClient()->getEmail();

        if (null === $email || null === $conversation->getAccessToken()) {
            return false;
        }

        return $this->send(
            $conversation->getOrganization(),
            (new Email())
                ->to($email)
                ->subject($subject)
                ->text($this->messages->render($conversation->getOrganization(), $messageKey, $variables + [
                    'client' => $conversation->getClient()->getDisplayName(),
                    'objet' => $conversation->getTitle(),
                    'lien' => $this->urls->generate('tracking_show', ['slug' => $conversation->getOrganization()->getSlug(), 'token' => $conversation->getAccessToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                ])),
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
            $this->logger->error('Courriel de test non envoyé : '.$e->getMessage(), ['organization' => $organization->getSlug()]);

            return self::explain($e->getMessage());
        }
    }

    /** Le message du serveur est en anglais et technique : on dit quoi corriger. */
    public static function explain(string $error): string
    {
        if (str_contains($error, '535') || str_contains($error, 'Failed to authenticate')) {
            return 'le serveur refuse l\'adresse ou le mot de passe. Vérifiez-les en vous connectant au webmail, puis ressaisissez le mot de passe.';
        }

        if (str_contains($error, 'Connection could not be established') || str_contains($error, 'timed out')) {
            return 'le serveur SMTP est injoignable. Vérifiez son nom et le port.';
        }

        return $error;
    }

    /** Ce que l'admin a enregistré, sinon la configuration du serveur. */
    public function defaultSender(): Address
    {
        return $this->mailSettings->current()?->toAddress() ?? Address::create($this->from);
    }

    private function send(Organization $organization, Email $email): bool
    {
        try {
            $transport = $this->transports->for($organization);

            if (null === $transport) {
                $this->mailer->send($email->from($this->defaultSender()));

                return true;
            }

            $email->from(new Address((string) $organization->getSmtpUsername(), $organization->getName()));
            $transport->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Courriel non envoyé : '.$e->getMessage(), ['organization' => $organization->getSlug()]);

            return false;
        }
    }
}
