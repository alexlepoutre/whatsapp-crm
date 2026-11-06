<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;

class ContactFormTest extends AppTestCase
{
    /** @param array<string, string> $overrides */
    private function submitContact(array $overrides = []): void
    {
        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', $overrides + [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[phone]' => '06 12 34 56 78',
            'contact[subject]' => 'Demande de devis',
            'contact[body]' => 'Pouvez-vous me rappeler ?',
        ]);
    }

    public function testSubmissionLeadsToPrivateLinkAndSendsBothEmails(): void
    {
        $this->submitContact();

        self::assertResponseRedirects();
        self::assertEmailCount(2);

        $link = self::getMailerMessage(0);
        self::assertEmailAddressContains($link, 'To', 'dominique@example.test');

        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        self::assertEmailTextBodyContains($link, '/itroom/suivi/'.$conversation->getAccessToken());

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Demande de devis');
    }

    public function testHoneypotStoresNothing(): void
    {
        $this->submitContact(['contact[website]' => 'http://spam.test']);

        self::assertSame(0, $this->em->getRepository(Client::class)->count([]));
        self::assertEmailCount(0);
    }

    public function testFormIsLinkedToExistingWhatsAppClientWithoutOverwritingName(): void
    {
        $this->postWebhook($this->textMessage());
        $this->submitContact();

        self::assertSame(1, $this->em->getRepository(Client::class)->count([]));

        $this->em->clear();
        $client = $this->em->getRepository(Client::class)->findOneBy([]);
        self::assertSame('Camille', $client->getName());
        self::assertSame('dominique@example.test', $client->getEmail());
        self::assertCount(2, $client->getConversations());
    }

    public function testClientCanReplyFromPrivateLink(): void
    {
        $this->submitContact();
        $this->client->followRedirect();
        $this->client->submitForm('Envoyer mon message', ['message[body]' => 'Une précision.']);

        self::assertResponseRedirects();
        self::assertSame(2, $this->em->getRepository(Message::class)->count([]));
    }

    public function testPageIsSpecificToOrganizationAndCarriesWhatsAppCode(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');

        $this->client->request('GET', '/dufermont');

        self::assertSelectorTextContains('.public__org', 'Dufermont');
        self::assertSelectorExists('a[href="https://wa.me/33600000000?text='.rawurlencode('Bonjour Dufermont, je vous contacte depuis votre site (#dufermont).').'"]');
        self::assertSelectorTextContains('.lead', 'envoyez-le d\'abord tel quel');
    }

    public function testHomePresentsTheToolWithFaviconAndAccess(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.home__brand', 'Posez votre question');
        self::assertSelectorExists('a[href="/itroom"]');
        self::assertSelectorExists('a.btn--whatsapp[href^="https://wa.me/33600000000?text="] svg.wa-logo');
        self::assertSelectorExists('a[href="/connexion"]');
        self::assertSelectorExists('link[rel="icon"][href="/favicon.svg"]');
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['/mentions-legales', '430 032 854'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/mentions-legales', 'OVH SAS'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/confidentialite', 'OpenAI'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/cgu', 'Usages interdits'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/cgv', 'Lille Métropole'])]
    public function testLegalPagesAreAvailable(string $url, string $text): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $text);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['/'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/itroom'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/connexion'])]
    public function testFooterLinksToLegalPages(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertSelectorTextContains('.site-footer', 'Alexis Lepoutre');
        self::assertSelectorTextContains('.site-footer', 'OVH à Roubaix');
        foreach (['/mentions-legales', '/confidentialite', '/cgu', '/cgv'] as $link) {
            self::assertSelectorExists('.site-footer a[href="'.$link.'"]');
        }
    }

    public function testLegalAddressCannotBecomeAnOrganization(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Organization('cgv', 'Pirate');
    }

    public function testUnknownOrganizationPageIsNotFound(): void
    {
        $this->client->request('GET', '/inconnue');

        self::assertResponseStatusCodeSame(404);
    }

    public function testApplicationRoutesWinOverOrganizationPages(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connexion');
    }

    public function testReservedAddressCannotBecomeAnOrganization(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Organization('admin', 'Pirate');
    }

    public function testFormRequestGoesToTheOrganizationOfThePage(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->client->request('GET', '/dufermont');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);

        $client = $this->em->getRepository(Client::class)->findOneBy(['email' => 'dominique@example.test']);
        self::assertSame($other->getId(), $client->getOrganization()->getId());
        self::assertEmailAddressContains(self::getMailerMessage(1), 'To', 'alerte@dufermont.test');
    }

    public function testPrivateLinkFailsUnderAnotherOrganizationAddress(): void
    {
        $this->createOrganization('dufermont', 'Dufermont');
        $this->submitContact();
        $token = $this->em->getRepository(Conversation::class)->findOneBy([])->getAccessToken();

        $this->client->request('GET', '/dufermont/suivi/'.$token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $this->client->request('GET', '/itroom/suivi/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTrackingPageNeverShowsAnotherConversation(): void
    {
        $this->submitContact();
        $first = $this->client->getResponse()->headers->get('Location');
        $this->submitContact(['contact[subject]' => 'Sujet confidentiel', 'contact[body]' => 'Secret']);

        $this->client->request('GET', $first);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Secret', (string) $this->client->getResponse()->getContent());
    }
}
