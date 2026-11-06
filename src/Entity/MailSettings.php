<?php

namespace App\Entity;

use App\Repository\MailSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Mime\Address;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Expéditeur des courriels d'une organisation sans boîte propre. Une seule ligne. Tant qu'elle
 * n'existe pas, c'est la variable MAIL_FROM qui s'applique.
 */
#[ORM\Entity(repositoryClass: MailSettingsRepository::class)]
class MailSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le nom de l\'expéditeur.')]
    #[Assert\Length(max: 120)]
    private string $fromName;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Indiquez l\'adresse de l\'expéditeur.')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')]
    #[Assert\Length(max: 180)]
    private string $fromAddress;

    public function __construct(string $fromName, string $fromAddress)
    {
        $this->fromName = $fromName;
        $this->fromAddress = $fromAddress;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = (string) $fromName;

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): static
    {
        $this->fromAddress = (string) $fromAddress;

        return $this;
    }

    public function toAddress(): Address
    {
        return new Address($this->fromAddress, $this->fromName);
    }
}
