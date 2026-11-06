<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Message;

class ContactFormTest extends AppTestCase
{
    /** @param array<string, string> $overrides */
    private function submitContact(array $overrides = []): void
    {
        $this->client->request('GET', '/');
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
        self::assertEmailTextBodyContains($link, '/suivi/'.$conversation->getAccessToken());

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

    public function testUnknownTokenIsNotFound(): void
    {
        $this->client->request('GET', '/suivi/'.str_repeat('a', 64));

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
