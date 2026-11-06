<?php

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Enum\MessageSender;
use App\Service\MessageCatalog;

class MessageTemplateTest extends AppTestCase
{
    private function save(string $key, string $text): void
    {
        $crawler = $this->client->request('GET', '/admin/reglages');
        $form = $crawler->filter('form[action$="/'.$key.'"]')->form(['text' => $text]);
        $this->client->submit($form);
    }

    private function organization(): Organization
    {
        $this->em->clear();

        return $this->em->find(Organization::class, $this->itroom->getId());
    }

    public function testEveryMessageIsEditableInSettings(): void
    {
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/reglages');

        self::assertSame(8, $this->client->getCrawler()->filter('form.template')->count());
    }

    public function testCustomWhatsAppMessageIsSentToTheClient(): void
    {
        $this->loginAsOwner();
        $this->save(MessageCatalog::VOICE_ECHO, 'Compris pour {organisation} : {transcription}');

        $this->postWebhook(['messages' => [[
            'from' => '33612345678', 'id' => 'wamid.v', 'type' => 'audio', 'audio' => ['id' => '1'], 'transcription' => 'Bonjour',
        ]]]);

        $bot = $this->em->getRepository(Message::class)->findBy(['sender' => MessageSender::BOT], ['id' => 'ASC']);
        self::assertSame('Compris pour IT-Room : Bonjour', $bot[0]->getBody());
    }

    public function testCustomEmailIsSent(): void
    {
        $this->loginAsOwner();
        $this->save(MessageCatalog::TRACKING_LINK, 'Salut {client}, voici ton lien : {lien}');

        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);

        $token = $this->em->getRepository(Conversation::class)->findOneBy([])->getAccessToken();
        self::assertEmailTextBodyContains(self::getMailerMessage(0), 'Salut Dominique, voici ton lien : http');
        self::assertEmailTextBodyContains(self::getMailerMessage(0), '/itroom/suivi/'.$token);
    }

    public function testEmailWithoutLinkIsRefused(): void
    {
        $this->loginAsOwner();

        $this->save(MessageCatalog::TRACKING_LINK, 'Merci pour votre demande.');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--error', '{lien}');
        self::assertNull($this->organization()->getMessageTemplate(MessageCatalog::TRACKING_LINK));
    }

    public function testEmptyMessageIsRefused(): void
    {
        $this->loginAsOwner();

        $this->save(MessageCatalog::CLOSED, '   ');

        self::assertNull($this->organization()->getMessageTemplate(MessageCatalog::CLOSED));
    }

    public function testResetRestoresOriginalText(): void
    {
        $this->loginAsOwner();
        $this->save(MessageCatalog::CLOSED, 'Clôturé !');
        self::assertSame('Clôturé !', $this->organization()->getMessageTemplate(MessageCatalog::CLOSED));

        $this->client->request('GET', '/admin/reglages');
        $this->client->submitForm('Rétablir le texte d\'origine');

        self::assertNull($this->organization()->getMessageTemplate(MessageCatalog::CLOSED));
    }

    public function testTextIdenticalToOriginalIsNotStored(): void
    {
        $this->loginAsOwner();

        $this->save(MessageCatalog::CONTINUE, (new MessageCatalog())->defaultText(MessageCatalog::CONTINUE));

        self::assertNull($this->organization()->getMessageTemplate(MessageCatalog::CONTINUE));
    }

    public function testMessageOfOneOrganizationDoesNotReachAnother(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->loginAsOwner();
        $this->save(MessageCatalog::CLOSED, 'Texte IT-Room');

        $this->em->clear();
        self::assertNull($this->em->find(Organization::class, $other->getId())->getMessageTemplate(MessageCatalog::CLOSED));
    }

    public function testClientCannotInjectVariables(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', text: 'Mon {lien} {organisation}'));

        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Mon {lien} {organisation}');
    }

    public function testSettingsGiveTheWhatsAppButtonCodeOfTheOrganization(): void
    {
        $this->loginAsOwner();

        $crawler = $this->client->request('GET', '/admin/reglages');
        $code = $crawler->filter('#snippet')->text(normalizeWhitespace: false);

        self::assertStringContainsString('https://wa.me/33600000000?text=', $code);
        self::assertStringContainsString('%23itroom', $code);
        self::assertStringContainsString('position:fixed', $code);
        self::assertStringContainsString('<svg', $code);
        self::assertStringNotContainsString('wa-logo', $code);
        self::assertStringNotContainsString('<script', $code);
    }

    public function testUnknownKeyIsNotFound(): void
    {
        $this->loginAsOwner();

        $this->client->request('POST', '/admin/reglages/messages/inconnu');

        self::assertResponseStatusCodeSame(404);
    }
}
