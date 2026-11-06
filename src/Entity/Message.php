<?php

namespace App\Entity;

use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(length: 20, enumType: MessageSender::class)]
    private MessageSender $sender;

    #[ORM\Column(type: 'text')]
    private string $body;

    /** Unique : c'est ce qui rend sans effet un webhook que Meta renvoie deux fois. */
    #[ORM\Column(length: 191, unique: true, nullable: true)]
    private ?string $waMessageId = null;

    /** Nul pour un message reçu : seul ce qui part a un suivi d'envoi. */
    #[ORM\Column(length: 20, nullable: true, enumType: DeliveryStatus::class)]
    private ?DeliveryStatus $deliveryStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    /** Moment où quelqu'un a ouvert la conversation : les coches du client sont alors passées au bleu. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Conversation $conversation, MessageSender $sender, string $body)
    {
        $this->conversation = $conversation;
        $this->sender = $sender;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();

        if (MessageSender::CLIENT !== $sender) {
            $this->deliveryStatus = DeliveryStatus::PENDING;
        }

        $conversation->addMessage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getSender(): MessageSender
    {
        return $this->sender;
    }

    public function isFromClient(): bool
    {
        return MessageSender::CLIENT === $this->sender;
    }

    public function isFromBot(): bool
    {
        return MessageSender::BOT === $this->sender;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getWaMessageId(): ?string
    {
        return $this->waMessageId;
    }

    public function setWaMessageId(?string $waMessageId): static
    {
        $this->waMessageId = $waMessageId;

        return $this;
    }

    public function getDeliveryStatus(): ?DeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function markDelivery(DeliveryStatus $status, ?string $failureReason = null): void
    {
        $this->deliveryStatus = $status;
        $this->failureReason = null === $failureReason ? null : mb_substr($failureReason, 0, 255);
    }

    /** Applique un statut reçu de Meta, sans jamais revenir en arrière. */
    public function advanceDelivery(DeliveryStatus $status, ?string $failureReason = null): void
    {
        if (null === $this->deliveryStatus || $status->rank() > $this->deliveryStatus->rank()) {
            $this->markDelivery($status, $failureReason);
        }
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(): void
    {
        $this->readAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
