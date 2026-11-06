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
        $this->client->submitForm('Supprimer equipe@itroom.test');

        self::assertSame(0, $this->em->getRepository(User::class)->count(['email' => 'equipe@itroom.test']));
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
