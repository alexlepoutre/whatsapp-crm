<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une entreprise qui reçoit ses messages ici (itroom, dufermont…). Tout ce qui concerne
 * un client est rattaché à une organisation : c'est la frontière entre leurs données.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[UniqueEntity('slug')]
class Organization
{
    /** Ces adresses appartiennent à l'application : une organisation ne peut pas les prendre. */
    public const RESERVED_SLUGS = ['admin', 'connexion', 'deconnexion', 'webhook', 'suivi', 'css', 'fonts', 'index'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Sert dans l'URL publique et dans le code WhatsApp (#itroom). */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\Regex('/^[a-z0-9][a-z0-9-]*$/', message: 'Minuscules, chiffres et tirets uniquement.')]
    private string $slug;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name;

    /** Adresse prévenue des nouveaux messages. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $notificationEmail = null;

    #[ORM\Column]
    private bool $botEnabled = true;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Écrivez le message automatique.')]
    #[Assert\Length(max: 1000)]
    private string $botMessage = "Bonjour, merci pour votre message. Je l'ai bien reçu et je vous réponds dès que possible.";

    public function __construct(string $slug, string $name)
    {
        if (\in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException(\sprintf('L\'adresse « %s » est réservée.', $slug));
        }

        $this->slug = $slug;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->notificationEmail;
    }

    public function setNotificationEmail(?string $notificationEmail): static
    {
        $this->notificationEmail = $notificationEmail;

        return $this;
    }

    public function isBotEnabled(): bool
    {
        return $this->botEnabled;
    }

    public function setBotEnabled(bool $botEnabled): static
    {
        $this->botEnabled = $botEnabled;

        return $this;
    }

    public function getBotMessage(): string
    {
        return $this->botMessage;
    }

    public function setBotMessage(?string $botMessage): static
    {
        $this->botMessage = (string) $botMessage;

        return $this;
    }

    /** Le hook WhatsApp reconnaît ce code en tête du premier message et route vers ce CRM. */
    public function getWhatsAppCode(): string
    {
        return '#'.$this->slug;
    }
}
