<?php

namespace App\Entity;

use App\Enum\Channel;
use App\Enum\ConversationStatus;
use App\Enum\MessageSender;
use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fil d'échanges avec un client, sur un seul canal.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Index(columns: ['status', 'last_message_at'])]
class Conversation
{
    /** Hors de cette fenêtre, Meta refuse tout texte libre : seuls les modèles validés passent. */
    public const WHATSAPP_WINDOW = '-24 hours';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'conversations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    #[ORM\Column(length: 20, enumType: Channel::class)]
    private Channel $channel;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $subject;

    #[ORM\Column(length: 20, enumType: ConversationStatus::class)]
    private ConversationStatus $status = ConversationStatus::OPEN;

    /**
     * Jeton du lien privé, formulaire uniquement. Stocké en clair, contrairement à un mot
     * de passe : il faut le remettre dans chaque courriel, et il ne protège que des
     * messages qui se trouvent dans la même base.
     */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $accessToken = null;

    /** L'équipe a envoyé sa dernière réponse : le client doit choisir de clôturer ou de continuer. */
    #[ORM\Column(options: ['default' => false])]
    private bool $awaitingClosure = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastInboundAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    public function __construct(Client $client, Channel $channel, ?string $subject = null)
    {
        $this->client = $client;
        $this->channel = $channel;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();

        if (Channel::FORM === $channel) {
            $this->accessToken = bin2hex(random_bytes(32));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getOrganization(): Organization
    {
        return $this->client->getOrganization();
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function isWhatsApp(): bool
    {
        return Channel::WHATSAPP === $this->channel;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getTitle(): string
    {
        return $this->subject ?? 'Conversation WhatsApp';
    }

    public function getStatus(): ConversationStatus
    {
        return $this->status;
    }

    public function close(): void
    {
        $this->status = ConversationStatus::CLOSED;
        $this->awaitingClosure = false;
    }

    public function isAwaitingClosure(): bool
    {
        return $this->awaitingClosure;
    }

    public function askForClosure(): void
    {
        $this->awaitingClosure = true;
    }

    public function cancelClosureQuestion(): void
    {
        $this->awaitingClosure = false;
    }

    /** Le client n'a encore rien demandé de nouveau : la balle reste de son côté. */
    public function markAnswered(): void
    {
        $this->status = ConversationStatus::ANSWERED;
    }

    public function isClosed(): bool
    {
        return ConversationStatus::CLOSED === $this->status;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getLastInboundAt(): ?\DateTimeImmutable
    {
        return $this->lastInboundAt;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Message> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /** Ce que le client a dit en dernier : c'est ce qu'on veut lire dans une liste, pas la réponse du bot. */
    public function getLastClientMessage(): ?Message
    {
        return $this->messages->filter(static fn (Message $m) => $m->isFromClient())->last() ?: null;
    }

    /**
     * Le statut suit le dernier à avoir parlé : un message du client rouvre le fil, même
     * clôturé. Le bot ne change rien, sa réponse d'attente ne traite pas la demande.
     */
    public function addMessage(Message $message): void
    {
        $this->messages->add($message);
        $this->lastMessageAt = $message->getCreatedAt();

        match ($message->getSender()) {
            MessageSender::CLIENT => $this->receivedFromClient($message),
            MessageSender::OWNER => $this->status = ConversationStatus::ANSWERED,
            MessageSender::BOT => null,
        };
    }

    private function receivedFromClient(Message $message): void
    {
        $this->status = ConversationStatus::OPEN;
        $this->lastInboundAt = $message->getCreatedAt();
        $this->awaitingClosure = false;
    }

    public function isWhatsAppWindowOpen(): bool
    {
        return null !== $this->lastInboundAt
            && $this->lastInboundAt > new \DateTimeImmutable(self::WHATSAPP_WINDOW);
    }

    public function isQuietSince(\DateTimeImmutable $moment): bool
    {
        return null === $this->lastMessageAt || $this->lastMessageAt < $moment;
    }

    /** Sert aux tests et aux reprises de données. */
    public function setLastInboundAt(?\DateTimeImmutable $moment): void
    {
        $this->lastInboundAt = $moment;
    }
}
