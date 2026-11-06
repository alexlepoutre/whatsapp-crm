<?php

namespace App\Entity;

use App\Repository\OrganizationLogoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le logo vit en base : l'image de production n'a pas de volume, un fichier disparaîtrait au
 * déploiement. Entité à part pour ne pas charger l'image à chaque lecture de l'organisation.
 */
#[ORM\Entity(repositoryClass: OrganizationLogoRepository::class)]
class OrganizationLogo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 20)]
    private string $mimeType;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private mixed $content;

    public function __construct(Organization $organization, string $mimeType, string $content)
    {
        $this->organization = $organization;
        $this->replace($mimeType, $content);
    }

    public function replace(string $mimeType, string $content): void
    {
        $this->mimeType = $mimeType;
        $this->content = $content;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getContent(): string
    {
        return \is_resource($this->content) ? (string) stream_get_contents($this->content) : $this->content;
    }
}
