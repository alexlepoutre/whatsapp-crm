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
    private const DARK_TEXT = '#17283a';
    private const PAGE_BACKGROUND = '#e9edf1';

    public const RESERVED_SLUGS = ['admin', 'connexion', 'deconnexion', 'webhook', 'suivi', 'css', 'fonts', 'index', 'mentions-legales', 'confidentialite', 'cgu', 'cgv', 'v1', 'v2', 'v3'];

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

    /** Par défaut, le thème de l'application : une organisation sans réglage reste lisible. */
    #[ORM\Column(length: 7, options: ['default' => '#2346d5'])]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i', message: 'Choisissez une couleur.')]
    private string $primaryColor = '#2346d5';

    #[ORM\Column(length: 7, options: ['default' => '#14222f'])]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i', message: 'Choisissez une couleur.')]
    private string $secondaryColor = '#14222f';

    /** Change à chaque nouveau logo, pour que le cache des navigateurs ne garde pas l'ancien. Nul sans logo. */
    #[ORM\Column(nullable: true)]
    private ?int $logoVersion = null;

    /** Sert aussi d'expéditeur : OVH refuse d'envoyer au nom d'une autre boîte. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')]
    private ?string $smtpUsername = null;

    /** Chiffré par SecretBox, jamais en clair. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $smtpPassword = null;

    #[ORM\Column(length: 120, options: ['default' => 'ssl0.ovh.net'])]
    #[Assert\NotBlank(message: 'Indiquez le serveur SMTP.')]
    #[Assert\Hostname(requireTld: false, message: 'Ce nom de serveur n\'est pas valide.')]
    private string $smtpHost = 'ssl0.ovh.net';

    #[ORM\Column(options: ['default' => 465])]
    #[Assert\Choice(choices: [465, 587])]
    private int $smtpPort = 465;

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

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $color): static
    {
        $this->primaryColor = (string) $color;

        return $this;
    }

    public function getSecondaryColor(): string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(?string $color): static
    {
        $this->secondaryColor = (string) $color;

        return $this;
    }

    public function getLogoVersion(): ?int
    {
        return $this->logoVersion;
    }

    public function setLogoVersion(?int $logoVersion): static
    {
        $this->logoVersion = $logoVersion;

        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): static
    {
        $this->smtpUsername = $smtpUsername;

        return $this;
    }

    public function getSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(?string $encrypted): static
    {
        $this->smtpPassword = $encrypted;

        return $this;
    }

    public function getSmtpHost(): string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = (string) $smtpHost;

        return $this;
    }

    public function getSmtpPort(): int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;

        return $this;
    }

    public function hasSmtp(): bool
    {
        return null !== $this->smtpUsername && null !== $this->smtpPassword;
    }

    public function getPrimaryTextColor(): string
    {
        return self::readableTextOn($this->primaryColor);
    }

    public function getSecondaryTextColor(): string
    {
        return self::readableTextOn($this->secondaryColor);
    }

    /** Une couleur claire choisie pour les boutons serait illisible en texte de lien sur le fond. */
    public function getLinkColor(): string
    {
        return self::contrast($this->primaryColor, self::PAGE_BACKGROUND) >= 3 ? $this->primaryColor : self::DARK_TEXT;
    }

    private static function readableTextOn(string $color): string
    {
        return self::contrast($color, '#ffffff') >= self::contrast($color, self::DARK_TEXT) ? '#ffffff' : self::DARK_TEXT;
    }

    /** Rapport de contraste WCAG entre deux couleurs. */
    private static function contrast(string $first, string $second): float
    {
        $luminances = [self::luminance($first), self::luminance($second)];

        return (max($luminances) + .05) / (min($luminances) + .05);
    }

    private static function luminance(string $color): float
    {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return 0.0;
        }

        $channels = array_map(static function (string $hex): float {
            $value = hexdec($hex) / 255;

            return $value <= .03928 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));

        return .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2];
    }

    /** Le texte préparé par le bouton WhatsApp de la page publique. Le hook y repère « (#code) ». */
    public function getWhatsAppOpening(): string
    {
        return \sprintf('Bonjour %s, je vous contacte depuis votre site (%s).', $this->name, $this->getWhatsAppCode());
    }

    /** Le hook WhatsApp reconnaît ce code en tête du premier message et route vers ce CRM. */
    public function getWhatsAppCode(): string
    {
        return '#'.$this->slug;
    }
}
