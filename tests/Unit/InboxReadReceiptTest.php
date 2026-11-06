<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Enum\Channel;
use App\Enum\MessageSender;
use App\Repository\ClientRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Service\Inbox;
use App\Service\Notifier;
use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboxReadReceiptTest extends TestCase
{
    private function inbox(WhatsAppClient $whatsApp): Inbox
    {
        return new Inbox(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ClientRepository::class),
            $this->createStub(ConversationRepository::class),
            $this->createStub(MessageRepository::class),
            $whatsApp,
            $this->createStub(Notifier::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function conversation(Channel $channel = Channel::WHATSAPP): Conversation
    {
        return new Conversation(new Client(new Organization('itroom', 'IT-Room')), $channel);
    }

    private function clientMessage(Conversation $conversation, string $id): Message
    {
        return (new Message($conversation, MessageSender::CLIENT, 'Bonjour'))->setWaMessageId($id);
    }

    public function testOnlyLastUnreadMessageIsSentToMetaAndAllBecomeRead(): void
    {
        $conversation = $this->conversation();
        $first = $this->clientMessage($conversation, 'wamid.1');
        $last = $this->clientMessage($conversation, 'wamid.2');

        $whatsApp = $this->createMock(WhatsAppClient::class);
        $whatsApp->method('isConfigured')->willReturn(true);
        $whatsApp->expects(self::once())->method('markAsRead')->with('wamid.2');

        $this->inbox($whatsApp)->markAsRead($conversation);

        self::assertNotNull($first->getReadAt());
        self::assertNotNull($last->getReadAt());
    }

    public function testNothingIsSentTwice(): void
    {
        $conversation = $this->conversation();
        $this->clientMessage($conversation, 'wamid.1');

        $whatsApp = $this->createMock(WhatsAppClient::class);
        $whatsApp->method('isConfigured')->willReturn(true);
        $whatsApp->expects(self::once())->method('markAsRead');

        $inbox = $this->inbox($whatsApp);
        $inbox->markAsRead($conversation);
        $inbox->markAsRead($conversation);
    }

    public function testFailureLeavesMessagesUnreadForNextOpening(): void
    {
        $conversation = $this->conversation();
        $message = $this->clientMessage($conversation, 'wamid.1');

        $whatsApp = $this->createStub(WhatsAppClient::class);
        $whatsApp->method('isConfigured')->willReturn(true);
        $whatsApp->method('markAsRead')->willThrowException(new WhatsAppException('panne'));

        $this->inbox($whatsApp)->markAsRead($conversation);

        self::assertNull($message->getReadAt());
    }

    public function testNothingHappensWithoutWhatsAppConfiguration(): void
    {
        $conversation = $this->conversation();
        $this->clientMessage($conversation, 'wamid.1');

        $whatsApp = $this->createMock(WhatsAppClient::class);
        $whatsApp->method('isConfigured')->willReturn(false);
        $whatsApp->expects(self::never())->method('markAsRead');

        $this->inbox($whatsApp)->markAsRead($conversation);
    }

    public function testFormConversationIsIgnored(): void
    {
        $conversation = $this->conversation(Channel::FORM);
        new Message($conversation, MessageSender::CLIENT, 'Bonjour');

        $whatsApp = $this->createMock(WhatsAppClient::class);
        $whatsApp->method('isConfigured')->willReturn(true);
        $whatsApp->expects(self::never())->method('markAsRead');

        $this->inbox($whatsApp)->markAsRead($conversation);
    }
}
