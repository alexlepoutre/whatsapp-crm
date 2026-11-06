<?php

namespace App\Tests\Functional;

use App\Entity\Organization;
use App\Entity\User;

class OrganizationManagementTest extends AppTestCase
{
    private function loginAsSuperAdmin(): void
    {
        $this->em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /** @param array<string, string> $overrides */
    private function createThroughForm(array $overrides = []): void
    {
        $this->client->request('GET', '/admin/organisations');
        $this->client->submitForm('Créer l\'organisation', $overrides + [
            'organization_create[name]' => 'Dufermont',
            'organization_create[slug]' => 'dufermont',
            'organization_create[notificationEmail]' => 'alerte@dufermont.test',
        ]);
    }

    public function testSuperAdminCreatesOrganizationAndItsPageIsLive(): void
    {
        $this->loginAsSuperAdmin();
        $this->createThroughForm();

        self::assertResponseRedirects();
        $this->client->request('GET', '/dufermont');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.public__org', 'Dufermont');
    }

    public function testReservedAndDuplicateAddressesAreRefused(): void
    {
        $this->loginAsSuperAdmin();

        $this->createThroughForm(['organization_create[slug]' => 'admin']);
        self::assertResponseStatusCodeSame(422);

        $this->createThroughForm(['organization_create[slug]' => 'itroom']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->em->getRepository(Organization::class)->count([]));
    }

    public function testSuperAdminAddsAccountThatOnlySeesItsOrganization(): void
    {
        $this->loginAsSuperAdmin();
        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $this->client->submitForm('Créer le compte', [
            'account[email]' => 'Equipe@Itroom.test',
            'account[password]' => 'un-mot-de-passe-long',
        ]);

        self::assertResponseRedirects();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'equipe@itroom.test']);
        self::assertSame($this->itroom->getId(), $user->getOrganization()->getId());
        self::assertFalse($user->isSuperAdmin());
        self::assertNotSame('un-mot-de-passe-long', $user->getPassword());
    }

    public function testShortPasswordAndExistingEmailAreRefused(): void
    {
        $this->loginAsSuperAdmin();
        $url = '/admin/organisations/'.$this->itroom->getId();

        $this->client->request('GET', $url);
        $this->client->submitForm('Créer le compte', ['account[email]' => 'a@itroom.test', 'account[password]' => 'court']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', $url);
        $this->client->submitForm('Créer le compte', ['account[email]' => 'super@example.test', 'account[password]' => 'un-mot-de-passe-long']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuperAdminDeletesAccount(): void
    {
        $this->loginAsSuperAdmin();
        $account = (new User('equipe@itroom.test'))->setPassword('x')->setOrganization($this->em->find(Organization::class, $this->itroom->getId()));
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $this->client->submitForm('Supprimer');

        self::assertSame(0, $this->em->getRepository(User::class)->count(['email' => 'equipe@itroom.test']));
    }

    private function teamAccount(string $email = 'equipe@itroom.test'): User
    {
        $account = (new User($email))->setPassword('ancien-hash')->setOrganization($this->em->find(Organization::class, $this->itroom->getId()));
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testSuperAdminChangesEmailAndKeepsPasswordWhenEmpty(): void
    {
        $this->loginAsSuperAdmin();
        $id = $this->teamAccount()->getId();

        $this->client->request('GET', '/admin/organisations/comptes/'.$id);
        $this->client->submitForm('Enregistrer le compte', ['account_edit[email]' => 'Nouvelle@Itroom.test']);

        self::assertResponseRedirects();
        $this->em->clear();
        $user = $this->em->find(User::class, $id);
        self::assertSame('nouvelle@itroom.test', $user->getEmail());
        self::assertSame('ancien-hash', $user->getPassword());
    }

    public function testSuperAdminChangesPassword(): void
    {
        $this->loginAsSuperAdmin();
        $id = $this->teamAccount()->getId();

        $this->client->request('GET', '/admin/organisations/comptes/'.$id);
        $this->client->submitForm('Enregistrer le compte', ['account_edit[password]' => 'un-nouveau-mot-de-passe']);

        $this->em->clear();
        $user = $this->em->find(User::class, $id);
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'un-nouveau-mot-de-passe'));
    }

    public function testAccountEditRefusesDuplicateEmailAndShortPassword(): void
    {
        $this->loginAsSuperAdmin();
        $id = $this->teamAccount()->getId();
        $url = '/admin/organisations/comptes/'.$id;

        $this->client->request('GET', $url);
        $this->client->submitForm('Enregistrer le compte', ['account_edit[email]' => 'super@example.test']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', $url);
        $this->client->submitForm('Enregistrer le compte', ['account_edit[password]' => 'court']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuperAdminAccountCannotBeEditedHere(): void
    {
        $this->loginAsSuperAdmin();
        $id = $this->em->getRepository(User::class)->findOneBy(['email' => 'super@example.test'])->getId();

        $this->client->request('GET', '/admin/organisations/comptes/'.$id);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNormalAccountCannotEditAccounts(): void
    {
        $this->loginAsOwner();
        $id = $this->teamAccount()->getId();

        $this->client->request('GET', '/admin/organisations/comptes/'.$id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDefaultMessagesAreShownWithoutStoringTheExample(): void
    {
        $this->loginAsSuperAdmin();
        $organization = $this->em->find(Organization::class, $this->itroom->getId());
        $organization->setBotMessage('Bonjour, ici Arnaud.');
        $this->em->flush();

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.defaults', 'Bonjour, ici Arnaud.');
        self::assertSelectorTextContains('.defaults', 'Votre demande est clôturée');
        self::assertSelectorTextContains('.defaults', 'compris de votre message vocal');
        self::assertSelectorTextContains('.defaults', 'est bien reçue par IT-Room');
        self::assertSelectorTextContains('.defaults', 'nouveau message de Camille Martin');
        self::assertSame(0, $this->em->getRepository(\App\Entity\Client::class)->count([]));
    }

    public function testDisabledBotIsShownAsDisabled(): void
    {
        $this->loginAsSuperAdmin();
        $this->em->find(Organization::class, $this->itroom->getId())->setBotEnabled(false);
        $this->em->flush();

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());

        self::assertSelectorTextContains('.defaults', 'il ne part pas');
    }

    public function testNormalAccountHasNoAccess(): void
    {
        $this->loginAsOwner();

        $this->client->request('GET', '/admin/organisations');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('.rail__nav', 'Organisations');
    }
}
