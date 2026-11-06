<?php

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\DeliveryStatus;
use App\Enum\MessageSender;
use App\Service\SecretBox;
use Doctrine\ORM\EntityManagerInterface;

class OrganizationMailTest extends AppTestCase
{
    private const PASSWORD = 'mot-de-passe/tres+secret';

    private function loginAsSuperAdmin(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /** @param array<string, mixed> $values */
    private function saveMail(array $values): void
    {
        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $this->client->submitForm('Enregistrer la messagerie', $values);
    }

    private function organization(): Organization
    {
        $this->em->clear();

        return $this->em->find(Organization::class, $this->itroom->getId());
    }

    public function testOvhDefaultsAreProposed(): void
    {
        self::assertSame('ssl0.ovh.net', $this->itroom->getSmtpHost());
        self::assertSame(465, $this->itroom->getSmtpPort());
    }

    public function testPasswordIsEncryptedAndNeverDisplayed(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveMail(['organization_mail[smtpUsername]' => 'contact@itroom.test', 'organization_mail[smtpPassword]' => self::PASSWORD]);

        self::assertResponseRedirects();
        $stored = (string) $this->organization()->getSmtpPassword();
        self::assertStringNotContainsString(self::PASSWORD, $stored);
        self::assertSame(self::PASSWORD, static::getContainer()->get(SecretBox::class)->decrypt($stored));

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::PASSWORD, $page);
        self::assertStringNotContainsString($stored, $page);
    }

    public function testEmptyPasswordKeepsStoredOne(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveMail(['organization_mail[smtpUsername]' => 'contact@itroom.test', 'organization_mail[smtpPassword]' => self::PASSWORD]);
        $stored = $this->organization()->getSmtpPassword();

        $this->saveMail(['organization_mail[smtpPort]' => '587']);

        self::assertSame($stored, $this->organization()->getSmtpPassword());
        self::assertSame(587, $this->organization()->getSmtpPort());
    }

    public function testAddressWithoutPasswordIsRefused(): void
    {
        $this->loginAsSuperAdmin();

        $this->saveMail(['organization_mail[smtpUsername]' => 'contact@itroom.test']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEmptyingAddressDisablesAndForgetsPassword(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveMail(['organization_mail[smtpUsername]' => 'contact@itroom.test', 'organization_mail[smtpPassword]' => self::PASSWORD]);

        $this->saveMail(['organization_mail[smtpUsername]' => '']);

        self::assertNull($this->organization()->getSmtpPassword());
    }

    public function testRepliesGoThroughTheServerOfTheOrganization(): void
    {
        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);

        // Un serveur injoignable : si l'envoi passait par le transport global, il réussirait.
        $this->loginAsSuperAdmin();
        $this->saveMail([
            'organization_mail[smtpUsername]' => 'contact@itroom.test',
            'organization_mail[smtpPassword]' => self::PASSWORD,
            'organization_mail[smtpHost]' => '127.0.0.1',
            'organization_mail[smtpPort]' => '587',
        ]);
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);

        $this->client->request('GET', '/admin/conversations/'.$conversation->getId());
        $this->client->submitForm('Envoyer la réponse', ['message[body]' => 'Voici le devis.']);

        $this->em->clear();
        $reply = $this->em->getRepository(Message::class)->findOneBy(['sender' => MessageSender::OWNER]);
        self::assertSame(DeliveryStatus::FAILED, $reply->getDeliveryStatus());
    }

    public function testOrganizationWithoutServerKeepsDefaultTransport(): void
    {
        $this->client->request('GET', '/itroom');
        $this->client->submitForm('Envoyer ma demande', [
            'contact[name]' => 'Dominique',
            'contact[email]' => 'dominique@example.test',
            'contact[subject]' => 'Devis',
            'contact[body]' => 'Bonjour',
        ]);

        self::assertEmailCount(2);
    }

    public function testTestEmailReportsFailure(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveMail([
            'organization_mail[smtpUsername]' => 'contact@itroom.test',
            'organization_mail[smtpPassword]' => self::PASSWORD,
            'organization_mail[smtpHost]' => '127.0.0.1',
            'organization_mail[smtpPort]' => '587',
        ]);

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $this->client->submitForm('Envoyer un courriel de test');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--error', 'Échec');
        self::assertStringNotContainsString(self::PASSWORD, (string) $this->client->getResponse()->getContent());
    }

    public function testNormalAccountCannotSeeOrChangeMailSettings(): void
    {
        $this->loginAsOwner();

        $this->client->request('POST', '/admin/organisations/'.$this->itroom->getId().'/courriel-test');

        self::assertResponseStatusCodeSame(403);
    }
}
