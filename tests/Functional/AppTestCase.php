<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AppTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /** @param array<string, mixed> $value */
    protected function postWebhook(array $value, ?string $signature = null): void
    {
        $body = json_encode(['entry' => [['changes' => [['value' => $value]]]]], \JSON_THROW_ON_ERROR);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, 'secret-de-test');

        $this->client->request('POST', '/webhook/whatsapp', server: ['HTTP_X_HUB_SIGNATURE_256' => $signature], content: $body);
    }

    /** @return array<string, mixed> */
    protected function textMessage(string $id = 'wamid.1', string $from = '33612345678', string $text = 'Bonjour'): array
    {
        return [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Camille']]],
            'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
        ];
    }

    protected function loginAsOwner(): void
    {
        $user = (new User('moi@example.test'))->setPassword('inutilisé');
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
