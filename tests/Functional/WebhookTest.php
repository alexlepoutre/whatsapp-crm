<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\ConversationStatus;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;

class WebhookTest extends AppTestCase
{
    public function testMetaVerificationEchoesChallenge(): void
    {
        $this->client->request('GET', '/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=jeton-de-test&hub.challenge=1234');

        self::assertResponseIsSuccessful();
        self::assertSame('1234', $this->client->getResponse()->getContent());
    }

    public function testWrongVerifyTokenIsRefused(): void
    {
        $this->client->request('GET', '/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1234');

        self::assertResponseStatusCodeSame(403);
    }

    public function testForgedSignatureIsRefusedAndStoresNothing(): void
    {
        $this->postWebhook($this->textMessage(), 'sha256=fausse');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Message::class)->count([]));
    }

    public function testIncomingMessageCreatesClientAndBotAnswersOnce(): void
    {
        $this->postWebhook($this->textMessage('wamid.1'));
        $this->postWebhook($this->textMessage('wamid.2', text: 'Vous êtes là ?'));

        self::assertResponseIsSuccessful();

        $client = $this->em->getRepository(Client::class)->findOneBy(['phone' => '33612345678']);
        self::assertSame('Camille', $client->getName());

        $messages = $this->em->getRepository(Message::class)->findAll();
        self::assertCount(3, $messages);
        self::assertCount(1, array_filter($messages, static fn (Message $m) => MessageSender::BOT === $m->getSender()));
        self::assertSame(1, $this->em->getRepository(Conversation::class)->count([]));

        // Sans jeton Meta, rien ne doit se prétendre envoyé.
        $bot = $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::BOT]);
        self::assertSame(DeliveryStatus::SIMULATED, $bot->getDeliveryStatus());
    }

    public function testRedeliveredWebhookIsIgnored(): void
    {
        $this->postWebhook($this->textMessage('wamid.1'));
        $this->postWebhook($this->textMessage('wamid.1'));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(Message::class)->count(['sender' => MessageSender::CLIENT]));
    }

    public function testMediaMessageIsKeptWithItsCaption(): void
    {
        $this->postWebhook([
            'messages' => [['from' => '33612345678', 'id' => 'wamid.9', 'type' => 'image', 'image' => ['caption' => 'Ma facture']]],
        ]);

        $message = $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.9']);
        self::assertStringContainsString('Photo', $message->getBody());
        self::assertStringContainsString('Ma facture', $message->getBody());
    }

    public function testDeliveryStatusNeverGoesBackwards(): void
    {
        $this->postWebhook($this->textMessage());
        $bot = $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::BOT]);
        $bot->setWaMessageId('wamid.out');
        $this->em->flush();

        $this->postWebhook(['statuses' => [['id' => 'wamid.out', 'status' => 'read']]]);
        $this->postWebhook(['statuses' => [['id' => 'wamid.out', 'status' => 'delivered']]]);

        $this->em->clear();
        self::assertSame(DeliveryStatus::READ, $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.out'])->getDeliveryStatus());
    }

    public function testClientMessageReopensClosedConversation(): void
    {
        $this->postWebhook($this->textMessage('wamid.1'));
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $conversation->close();
        $this->em->flush();

        $this->postWebhook($this->textMessage('wamid.2'));

        $this->em->clear();
        self::assertSame(ConversationStatus::OPEN, $this->em->getRepository(Conversation::class)->findOneBy([])->getStatus());
    }
}
