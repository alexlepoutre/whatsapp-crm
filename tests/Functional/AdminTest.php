<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;

class AdminTest extends AppTestCase
{
    public function testAdminRequiresLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/connexion');
    }

    public function testInboxListsWaitingConversation(): void
    {
        $this->postWebhook($this->textMessage());
        $this->loginAsOwner();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.row__who', 'Camille');
        self::assertSelectorTextContains('.display__digits', '01');
        // L'aperçu doit montrer le message du client, pas la réponse automatique qui le suit.
        self::assertSelectorTextContains('.row__what', 'Bonjour');
        self::assertSelectorTextNotContains('.row__what', 'merci pour votre message');
    }

    public function testReplyOnWhatsAppMarksConversationAnswered(): void
    {
        $this->postWebhook($this->textMessage());
        $this->loginAsOwner();
        $id = $this->em->getRepository(Conversation::class)->findOneBy([])->getId();

        $this->client->request('GET', '/admin/conversations/'.$id);
        $this->client->submitForm('Envoyer la réponse', ['message[body]' => 'Je vous rappelle.']);

        $this->em->clear();
        self::assertSame(ConversationStatus::ANSWERED, $this->em->getRepository(Conversation::class)->find($id)->getStatus());
        self::assertSame(DeliveryStatus::SIMULATED, $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::OWNER])->getDeliveryStatus());
    }

    public function testReplyIsBlockedOutsideWhatsAppWindow(): void
    {
        $this->postWebhook($this->textMessage());
        $this->loginAsOwner();
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $conversation->setLastInboundAt(new \DateTimeImmutable('-25 hours'));
        $this->em->flush();

        $this->client->request('GET', '/admin/conversations/'.$conversation->getId());

        self::assertSelectorTextContains('.notice', '24 heures');
        self::assertSelectorNotExists('textarea');
    }

    public function testReplyOnFormConversationEmailsPrivateLink(): void
    {
        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);

        $this->loginAsOwner();
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);

        $this->client->request('GET', '/admin/conversations/'.$conversation->getId());
        $this->client->submitForm('Envoyer la réponse', ['message[body]' => 'Voici le devis.']);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'dominique@example.test');
        self::assertEmailTextBodyContains($email, 'Voici le devis.');
        self::assertEmailTextBodyContains($email, '/itroom/suivi/'.$conversation->getAccessToken());
    }

    public function testAccountOnlySeesItsOwnOrganization(): void
    {
        $this->postWebhook($this->textMessage());
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $clientId = $conversation->getClient()->getId();
        $conversationId = $conversation->getId();
        $this->loginAsOwner($this->createOrganization('dufermont', 'Dufermont'));

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.row');
        self::assertSelectorTextContains('.display__digits', '00');

        $this->client->request('GET', '/admin/clients');
        self::assertSelectorNotExists('a.row');

        $this->client->request('GET', '/admin/conversations/'.$conversationId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/clients/'.$clientId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/clients/'.$clientId.'/supprimer');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/conversations/'.$conversationId.'/cloturer');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNormalAccountCannotSwitchOrganization(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->loginAsOwner();

        $this->client->request('POST', '/admin/organisation/'.$other->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminSwitchesBetweenOrganizations(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->postWebhook($this->textMessage(), slug: 'dufermont');
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        // Dufermont vient avant IT-Room par ordre alphabétique.
        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('.rail__org', 'Dufermont');
        self::assertSelectorExists('a.row');

        $this->client->submitForm('IT-Room 0');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.rail__org', 'IT-Room');
        self::assertSelectorNotExists('a.row');
        self::assertSame('Dufermont', $other->getName());
    }

    public function testSuperAdminSeesWaitingCountOfEveryOrganization(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');
        $this->postWebhook($this->textMessage('wamid.1', '33611111111'));
        $this->postWebhook($this->textMessage('wamid.2', '33622222222'));
        $this->postWebhook($this->textMessage('wamid.3', '33633333333'), slug: 'dufermont');
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/admin');

        $items = $crawler->filter('.orgs__item')->each(static fn ($node) => preg_replace('/\s+/', ' ', trim($node->text())));
        self::assertSame(['Dufermont 1', 'IT-Room 2'], $items);
    }

    public function testSuperAdminOpensOnOrganizationWithWaitingMessages(): void
    {
        // Dufermont vient d'abord par ordre alphabétique, mais c'est IT-Room qui a un message.
        $this->createOrganization('dufermont', 'Dufermont');
        $this->postWebhook($this->textMessage());
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('.banner__name', 'IT-Room');
        self::assertSelectorTextContains('.row__who', 'Camille');
    }

    public function testNormalAccountDoesNotSeeOtherOrganizations(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');
        $this->postWebhook($this->textMessage(), slug: 'dufermont');
        $this->loginAsOwner();

        $this->client->request('GET', '/admin');

        self::assertSelectorNotExists('.orgs');
        self::assertStringNotContainsString('Dufermont', (string) $this->client->getResponse()->getContent());
    }

    public function testSettingsAreSavedForCurrentOrganizationOnly(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/reglages');
        self::assertSelectorExists('a[href="/itroom"]');
        $this->client->submitForm('Enregistrer les réglages', ['settings[botMessage]' => 'Message IT-Room']);

        $this->em->clear();
        self::assertSame('Message IT-Room', $this->em->getRepository(Organization::class)->findOneBy(['slug' => 'itroom'])->getBotMessage());
        self::assertNotSame('Message IT-Room', $this->em->getRepository(Organization::class)->find($other->getId())->getBotMessage());
    }

    public function testFormConversationShowsThePrivateLink(): void
    {
        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/conversations/'.$conversation->getId());

        self::assertSelectorExists('.private-link a[href$="/itroom/suivi/'.$conversation->getAccessToken().'"]');
        self::assertSelectorExists('.private-link [data-copy]');
    }

    public function testWhatsAppConversationHasNoPrivateLink(): void
    {
        $this->postWebhook($this->textMessage());
        $this->loginAsOwner();
        $id = $this->em->getRepository(Conversation::class)->findOneBy([])->getId();

        $this->client->request('GET', '/admin/conversations/'.$id);

        self::assertSelectorNotExists('.private-link');
    }

    public function testDeletingClientRemovesConversations(): void
    {
        $this->postWebhook($this->textMessage());
        $this->loginAsOwner();
        $id = $this->em->getRepository(Client::class)->findOneBy([])->getId();

        $this->client->request('GET', '/admin/clients/'.$id);
        $this->client->submitForm('Supprimer ce client');

        self::assertResponseRedirects('/admin/clients');
        self::assertSame(0, $this->em->getRepository(Conversation::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Message::class)->count([]));
    }

    public function testDuplicatePhoneInClientFormIsRejectedAfterNormalization(): void
    {
        $this->postWebhook($this->textMessage('wamid.1', '33612345678'));
        $this->postWebhook($this->textMessage('wamid.2', '33699999999'));
        $this->loginAsOwner();
        $other = $this->em->getRepository(Client::class)->findOneBy(['phone' => '33699999999']);

        $this->client->request('GET', '/admin/clients/'.$other->getId());
        $this->client->submitForm('Enregistrer la fiche', ['client[phone]' => '06 12 34 56 78']);

        self::assertResponseStatusCodeSame(422);
    }
}
