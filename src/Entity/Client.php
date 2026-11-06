<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use App\Service\PhoneNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une personne pour une organisation, quel que soit le canal par lequel elle écrit. Si elle
 * contacte deux organisations, elle a deux fiches : rien n'est partagé entre elles.
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_client_organization_email', columns: ['organization_id', 'email'])]
#[ORM\UniqueConstraint(name: 'uniq_client_organization_phone', columns: ['organization_id', 'phone'])]
#[UniqueEntity(['organization', 'email'], message: 'Un autre client utilise déjà cette adresse.', errorPath: 'email')]
#[UniqueEntity(['organization', 'phone'], message: 'Un autre client utilise déjà ce numéro.', errorPath: 'phone')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $name = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Format Meta : chiffres seuls, indicatif compris (33612345678). */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(min: 8, max: 20)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Conversation> */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Conversation::class, cascade: ['remove'])]
    #[ORM\OrderBy(['lastMessageAt' => 'DESC'])]
    private Collection $conversations;

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;
        $this->createdAt = new \DateTimeImmutable();
        $this->conversations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : mb_strtolower(trim($email));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /** Normalise ici plutôt qu'au contrôleur : UniqueEntity doit comparer la forme stockée. */
    public function setPhone(?string $phone): static
    {
        $this->phone = PhoneNormalizer::normalize($phone);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Conversation> */
    public function getConversations(): Collection
    {
        return $this->conversations;
    }

    public function getDisplayName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        return $this->phone ? '+'.$this->phone : ($this->email ?? 'Client sans nom');
    }
}
