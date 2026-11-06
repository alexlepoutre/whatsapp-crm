<?php

namespace App\Tests\Functional;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BrandingTest extends AppTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function loginAsSuperAdmin(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User('super@example.test'))->setPassword('inutilisé')->setSuperAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    private function uploadedFile(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param array<string, mixed> $values */
    private function saveOrganization(array $values, ?UploadedFile $logo = null): void
    {
        $crawler = $this->client->request('GET', '/admin/organisations/'.$this->itroom->getId());
        $form = $crawler->selectButton('Enregistrer l\'organisation')->form($values);

        if ($logo) {
            $form['organization_edit[logo]']->upload($logo->getPathname());
        }

        $this->client->submit($form);
    }

    private function reloadedOrganization(): Organization
    {
        $this->em->clear();

        return $this->em->find(Organization::class, $this->itroom->getId());
    }

    public function testBannerShowsCurrentOrganizationName(): void
    {
        $this->loginAsOwner();

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('.banner__name', 'IT-Room');
    }

    public function testColorsApplyToAdminAndPublicPages(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveOrganization(['organization_edit[primaryColor]' => '#aa0000', 'organization_edit[secondaryColor]' => '#003322']);

        self::assertResponseRedirects();
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('--action: #aa0000', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/itroom');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('--action: #aa0000', $content);
        self::assertStringContainsString('--panel: #003322', $content);
    }

    public function testInvalidColorIsRefused(): void
    {
        $this->loginAsSuperAdmin();

        $this->saveOrganization(['organization_edit[primaryColor]' => '#12}body{']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLogoIsServedThenRemoved(): void
    {
        $this->loginAsSuperAdmin();
        $this->saveOrganization([], $this->uploadedFile(base64_decode(self::PNG), 'logo.png'));
        self::assertResponseRedirects();

        $this->client->request('GET', '/itroom/logo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame(base64_decode(self::PNG), $this->client->getResponse()->getContent());

        $this->client->request('GET', '/itroom');
        self::assertSelectorExists('img.public__logo');

        $this->saveOrganization(['organization_edit[removeLogo]' => true]);
        $this->client->request('GET', '/itroom/logo');
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->reloadedOrganization()->getLogoVersion());
    }

    public function testSvgAndNonImageFilesAreRefused(): void
    {
        $this->loginAsSuperAdmin();

        $this->saveOrganization([], $this->uploadedFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'));
        self::assertResponseStatusCodeSame(422);

        $this->saveOrganization([], $this->uploadedFile('pas une image', 'logo.png'));
        self::assertResponseStatusCodeSame(422);
    }

    public function testMissingLogoIsNotFound(): void
    {
        $this->client->request('GET', '/itroom/logo');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNormalAccountCannotChangeBranding(): void
    {
        $this->loginAsOwner();

        $this->client->request('POST', '/admin/organisations/'.$this->itroom->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTextStaysReadableOnLightAndDarkColors(): void
    {
        $organization = new Organization('test', 'Test');

        $organization->setPrimaryColor('#ffee00');
        self::assertSame('#17283a', $organization->getPrimaryTextColor());
        self::assertSame('#17283a', $organization->getLinkColor());

        $organization->setPrimaryColor('#003399');
        self::assertSame('#ffffff', $organization->getPrimaryTextColor());
        self::assertSame('#003399', $organization->getLinkColor());
    }

    public function testOpeningOrganizationShowsItsOwnBranding(): void
    {
        $other = $this->createOrganization('dufermont', 'Dufermont');
        $this->loginAsSuperAdmin();

        $this->client->request('GET', '/admin/organisations/'.$other->getId());

        self::assertSelectorTextContains('.banner__name', 'Dufermont');
        self::assertSame(ConversationStatus::OPEN->value, 'open');
    }
}
