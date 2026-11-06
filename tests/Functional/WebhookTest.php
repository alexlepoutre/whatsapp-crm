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
    public function testForgedSignatureIsRefusedAndStoresNothing(): void
    {
        $this->postWebhook($this->textMessage(), 'sha256=fausse');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Message::class)->count([]));
    }

    public function testUnknownOrganizationAnswersNotFound(): void
    {
        $this->postWebhook($this->textMessage(), slug: 'inconnue');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRoutingCodeIsRemovedFromMessage(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: '#itroom Bonjour, un devis ?'));
        $this->postWebhook($this->textMessage('wamid.2', '33699999999', '#itroom '));

        self::assertSame('Bonjour, un devis ?', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.1'])->getBody());
        self::assertSame('Conversation ouverte depuis la page de contact.', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.2'])->getBody());
    }

    public function testSamePhoneHasSeparateRecordPerOrganization(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');

        $this->postWebhook($this->textMessage('wamid.1'));
        $this->postWebhook($this->textMessage('wamid.2'), slug: 'dufermont');

        self::assertSame(2, $this->em->getRepository(Client::class)->count(['phone' => '33612345678']));
    }

    public function testStatusFromAnotherOrganizationIsIgnored(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');
        $this->postWebhook($this->textMessage());
        $bot = $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::BOT]);
        $bot->setWaMessageId('wamid.out');
        $this->em->flush();

        $this->postWebhook(['statuses' => [['id' => 'wamid.out', 'status' => 'read']]], slug: 'dufermont');

        $this->em->clear();
        self::assertNotSame(DeliveryStatus::READ, $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.out'])->getDeliveryStatus());
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

    public function testVoiceMessageShowsTranscription(): void
    {
        $this->postWebhook(['messages' => [[
            'from' => '33612345678', 'id' => 'wamid.v1', 'type' => 'audio',
            'audio' => ['id' => '123', 'voice' => true],
            'transcription' => 'Où en est ma commande ?',
        ]]]);

        $body = $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.v1'])->getBody();
        self::assertStringContainsString('transcription automatique', $body);
        self::assertStringContainsString('Où en est ma commande ?', $body);
    }

    public function testVoiceMessageIsKeptWhenTranscriptionFailed(): void
    {
        $this->postWebhook(['messages' => [[
            'from' => '33612345678', 'id' => 'wamid.v2', 'type' => 'audio', 'audio' => ['id' => '123'],
        ]]]);

        $body = $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.v2'])->getBody();
        self::assertStringContainsString('transcription indisponible', $body);
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
