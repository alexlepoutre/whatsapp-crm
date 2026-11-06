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

    public function testOpeningSentenceAsksForTheQuestionThenQuestionTriggersAlert(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Bonjour IT-Room, je vous contacte depuis votre site (#itroom).'));

        self::assertEmailCount(0);
        $bot = $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::BOT]);
        self::assertStringContainsString('Posez maintenant votre question', $bot->getBody());
        self::assertSame('Conversation ouverte depuis la page de contact.', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.1'])->getBody());

        $this->postWebhook($this->textMessage('wamid.2', text: 'Où en est ma commande ?'));

        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Où en est ma commande ?');
    }

    public function testQuestionWrittenInTheSameMessageAsOpeningIsKept(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Bonjour IT-Room, je vous contacte depuis votre site (#itroom). Où est ma commande ?'));

        self::assertSame('Où est ma commande ?', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.1'])->getBody());
        self::assertEmailCount(1);
    }

    public function testEditedOpeningStillLosesItsCode(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Salut (#itroom) ma commande ?'));

        self::assertSame('Salut  ma commande ?', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.1'])->getBody());
    }

    public function testHashtagOfTheClientIsNotTouched(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Voir #itroomfan sur le site'));

        self::assertSame('Voir #itroomfan sur le site', $this->em->getRepository(Message::class)->findOneBy(['waMessageId' => 'wamid.1'])->getBody());
    }

    public function testEveryWhatsAppMessageAlertsTheTeam(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Premier message'));
        self::assertEmailCount(1);

        $this->postWebhook($this->textMessage('wamid.2', text: 'Deuxième message'));
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Deuxième message');

        $this->postWebhook($this->textMessage('wamid.3', text: 'Troisième message'));
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Troisième message');
    }

    public function testRedeliveredMessageDoesNotAlertTwice(): void
    {
        $this->postWebhook($this->textMessage('wamid.1'));
        $this->postWebhook($this->textMessage('wamid.1'));

        self::assertEmailCount(0);
    }

    public function testAlertGoesToAllAccountsOfTheOrganizationOnce(): void
    {
        $itroom = $this->em->find(\App\Entity\Organization::class, $this->itroom->getId());
        $other = $this->createOrganization('dufermont', 'Dufermont');
        foreach ([['a@itroom.test', $itroom], ['alerte@itroom.test', $itroom], ['b@dufermont.test', $other]] as [$email, $organization]) {
            $this->em->persist((new \App\Entity\User($email))->setPassword('x')->setOrganization($organization));
        }
        $this->em->flush();

        $this->postWebhook($this->textMessage());

        self::assertEmailCount(1);
        $to = array_map(static fn ($address) => $address->getAddress(), self::getMailerMessage()->getTo());
        sort($to);
        self::assertSame(['a@itroom.test', 'alerte@itroom.test'], $to);
    }

    /** @return array<string, mixed> */
    private function buttonClick(string $id, string $choice): array
    {
        return ['messages' => [[
            'from' => '33612345678', 'id' => $id, 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $choice, 'title' => 'x']],
        ]]];
    }

    private function conversationStatus(): ConversationStatus
    {
        $this->em->clear();

        return $this->em->getRepository(Conversation::class)->findOneBy([])->getStatus();
    }

    private function answerConversation(): void
    {
        $this->postWebhook($this->textMessage('wamid.1'));
        $this->em->getRepository(Conversation::class)->findOneBy([])->markAnswered();
        $this->em->flush();
    }

    public function testCloseButtonClosesConversationAndTellsEveryone(): void
    {
        $this->answerConversation();

        $this->postWebhook($this->buttonClick('wamid.2', 'crm_close'));

        self::assertResponseIsSuccessful();
        self::assertSame(ConversationStatus::CLOSED, $this->conversationStatus());
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Le client a clôturé sa demande.');

        $replies = $this->em->getRepository(Message::class)->findBy(['sender' => MessageSender::BOT], ['id' => 'DESC']);
        self::assertStringContainsString('Votre demande est clôturée', $replies[0]->getBody());
    }

    public function testContinueButtonKeepsStatusAndSendsNoAlert(): void
    {
        $this->answerConversation();

        $this->postWebhook($this->buttonClick('wamid.2', 'crm_continue'));

        self::assertResponseIsSuccessful();
        self::assertSame(ConversationStatus::ANSWERED, $this->conversationStatus());
        self::assertEmailCount(0);

        $replies = $this->em->getRepository(Message::class)->findBy(['sender' => MessageSender::BOT], ['id' => 'DESC']);
        self::assertStringContainsString('écrivez votre message', $replies[0]->getBody());
    }

    public function testMessageAfterContinueNeedsAnAnswer(): void
    {
        $this->answerConversation();
        $this->postWebhook($this->buttonClick('wamid.2', 'crm_continue'));

        $this->postWebhook($this->textMessage('wamid.3', text: 'Une autre question'));

        self::assertSame(ConversationStatus::OPEN, $this->conversationStatus());
        self::assertEmailCount(1);
    }

    public function testRedeliveredButtonClickIsIgnored(): void
    {
        $this->answerConversation();
        $this->postWebhook($this->buttonClick('wamid.2', 'crm_close'));
        $this->postWebhook($this->buttonClick('wamid.2', 'crm_close'));

        self::assertEmailCount(0);
        self::assertSame(1, $this->em->getRepository(Message::class)->count(['waMessageId' => 'wamid.2']));
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

        $replies = $this->em->getRepository(Message::class)->findBy(['sender' => MessageSender::BOT], ['id' => 'ASC']);
        self::assertStringContainsString('Où en est ma commande ?', $replies[0]->getBody());
        self::assertStringContainsString('compris de votre message vocal', $replies[0]->getBody());
    }

    public function testTranscriptionIsSentBackEvenWithBotDisabled(): void
    {
        $this->em->find(\App\Entity\Organization::class, $this->itroom->getId())->setBotEnabled(false);
        $this->em->flush();

        $this->postWebhook(['messages' => [[
            'from' => '33612345678', 'id' => 'wamid.v3', 'type' => 'audio', 'audio' => ['id' => '1'], 'transcription' => 'Bonjour',
        ]]]);

        self::assertSame(1, $this->em->getRepository(Message::class)->count(['sender' => MessageSender::BOT]));
    }

    public function testNothingIsSentBackWithoutTranscription(): void
    {
        $this->em->find(\App\Entity\Organization::class, $this->itroom->getId())->setBotEnabled(false);
        $this->em->flush();

        $this->postWebhook(['messages' => [[
            'from' => '33612345678', 'id' => 'wamid.v4', 'type' => 'audio', 'audio' => ['id' => '1'],
        ]]]);

        self::assertSame(0, $this->em->getRepository(Message::class)->count(['sender' => MessageSender::BOT]));
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
