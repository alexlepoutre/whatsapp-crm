<?php

namespace App\Entity;

use App\Repository\SettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglages du bot, modifiables sans redéploiement. Une seule ligne.
 */
#[ORM\Entity(repositoryClass: SettingsRepository::class)]
class Settings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $botEnabled = true;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Écrivez le message automatique.')]
    #[Assert\Length(max: 1000)]
    private string $botMessage = "Bonjour, merci pour votre message. Je l'ai bien reçu et je vous réponds dès que possible.";

    public function getId(): ?int
    {
        return $this->id;
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
}
