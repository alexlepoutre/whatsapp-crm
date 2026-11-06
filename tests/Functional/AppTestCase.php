<?php

namespace App\Tests\Functional;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AppTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected Organization $itroom;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->itroom = $this->createOrganization('itroom', 'IT-Room');
    }

    /** @param array<string, mixed> $value */
    protected function postWebhook(array $value, ?string $signature = null, string $slug = 'itroom'): void
    {
        $body = json_encode(['entry' => [['changes' => [['value' => $value]]]]], \JSON_THROW_ON_ERROR);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, 'secret-de-test');

        $this->client->request('POST', '/webhook/relay/'.$slug, server: ['HTTP_X_HUB_SIGNATURE_256' => $signature], content: $body);
    }

    /** @return array<string, mixed> */
    protected function textMessage(string $id = 'wamid.1', string $from = '33612345678', string $text = 'Bonjour'): array
    {
        return [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Camille']]],
            'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
        ];
    }

    protected function createOrganization(string $slug, string $name): Organization
    {
        $organization = (new Organization($slug, $name))->setNotificationEmail('alerte@'.$slug.'.test');
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }

    protected function loginAsOwner(?Organization $organization = null): void
    {
        // Le noyau redémarre entre deux requêtes : l'organisation doit être relue dans le gestionnaire courant.
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $organization = $this->em->find(Organization::class, ($organization ?? $this->itroom)->getId());
        $user = (new User('moi@'.$organization->getSlug().'.test'))->setPassword('inutilisé')->setOrganization($organization);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
