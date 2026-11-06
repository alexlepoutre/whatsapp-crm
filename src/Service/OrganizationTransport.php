<?php

namespace App\Service;

use App\Entity\Organization;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Le serveur d'envoi propre à une organisation, ou null si elle n'en a pas.
 */
class OrganizationTransport
{
    public function __construct(private readonly SecretBox $secrets)
    {
    }

    public function for(Organization $organization): ?TransportInterface
    {
        $encrypted = $organization->getSmtpPassword();
        $password = null === $encrypted ? null : $this->secrets->decrypt($encrypted);

        if (null === $password || null === $organization->getSmtpUsername()) {
            return null;
        }

        // 465 chiffre dès la connexion ; sur 587, le chiffrement se négocie ensuite (STARTTLS).
        $transport = new EsmtpTransport($organization->getSmtpHost(), $organization->getSmtpPort(), 465 === $organization->getSmtpPort());
        $transport->setUsername($organization->getSmtpUsername());
        $transport->setPassword($password);

        // Les envois sont synchrones, parfois dans le webhook : un serveur muet ne doit pas tout bloquer.
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout(10);
        }

        return $transport;
    }
}
