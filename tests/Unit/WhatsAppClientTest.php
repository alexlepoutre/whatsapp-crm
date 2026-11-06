<?php

namespace App\Tests\Unit;

use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class WhatsAppClientTest extends TestCase
{
    public function testSendsTextAndReturnsMetaId(): void
    {
        $response = new JsonMockResponse(['messages' => [['id' => 'wamid.42']]]);
        $client = new WhatsAppClient(new MockHttpClient($response), 'jeton', '123', 'v24.0');

        self::assertSame('wamid.42', $client->sendText('33612345678', 'Bonjour'));
        self::assertSame('https://graph.facebook.com/v24.0/123/messages', $response->getRequestUrl());
        self::assertContains('Authorization: Bearer jeton', $response->getRequestOptions()['headers']);
        self::assertSame(
            ['messaging_product' => 'whatsapp', 'to' => '33612345678', 'type' => 'text', 'text' => ['body' => 'Bonjour']],
            json_decode($response->getRequestOptions()['body'], true),
        );
    }

    public function testMarksMessageAsRead(): void
    {
        $response = new JsonMockResponse(['success' => true]);
        $client = new WhatsAppClient(new MockHttpClient($response), 'jeton', '123', 'v24.0');

        $client->markAsRead('wamid.42');

        self::assertSame(
            ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.42'],
            json_decode($response->getRequestOptions()['body'], true),
        );
    }

    public function testShortReplyCarriesBothButtons(): void
    {
        $response = new JsonMockResponse(['messages' => [['id' => 'wamid.1']]]);
        $client = new WhatsAppClient(new MockHttpClient($response), 'jeton', '123', 'v24.0');

        self::assertSame('wamid.1', $client->sendTextWithChoice('33612345678', 'Votre commande est partie.', 'Question ?'));

        $sent = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame('interactive', $sent['type']);
        self::assertSame('Votre commande est partie.', $sent['interactive']['body']['text']);
        self::assertSame(
            [WhatsAppClient::CHOICE_CLOSE, WhatsAppClient::CHOICE_CONTINUE],
            array_map(static fn (array $b) => $b['reply']['id'], $sent['interactive']['action']['buttons']),
        );
        foreach ($sent['interactive']['action']['buttons'] as $button) {
            self::assertLessThanOrEqual(20, mb_strlen($button['reply']['title']));
        }
    }

    public function testLongReplyIsSentAsTextThenButtons(): void
    {
        $first = new JsonMockResponse(['messages' => [['id' => 'wamid.texte']]]);
        $second = new JsonMockResponse(['messages' => [['id' => 'wamid.choix']]]);
        $client = new WhatsAppClient(new MockHttpClient([$first, $second]), 'jeton', '123', 'v24.0');

        self::assertSame('wamid.texte', $client->sendTextWithChoice('33612345678', str_repeat('a', 1025), 'Question ?'));

        self::assertSame('text', json_decode($first->getRequestOptions()['body'], true)['type']);
        self::assertSame('interactive', json_decode($second->getRequestOptions()['body'], true)['type']);
    }

    public function testFailedButtonsDoNotMakeLongReplyFail(): void
    {
        $first = new JsonMockResponse(['messages' => [['id' => 'wamid.texte']]]);
        $second = new JsonMockResponse(['error' => ['message' => 'Refusé']], ['http_code' => 400]);
        $client = new WhatsAppClient(new MockHttpClient([$first, $second]), 'jeton', '123', 'v24.0');

        self::assertSame('wamid.texte', $client->sendTextWithChoice('33612345678', str_repeat('a', 1025), 'Question ?'));
    }

    public function testSurfacesMetaErrorMessage(): void
    {
        $response = new JsonMockResponse(['error' => ['message' => 'Hors fenêtre']], ['http_code' => 400]);
        $client = new WhatsAppClient(new MockHttpClient($response), 'jeton', '123', 'v24.0');

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('Hors fenêtre');

        $client->sendText('33612345678', 'Bonjour');
    }

    public function testIsNotConfiguredWithoutToken(): void
    {
        self::assertFalse((new WhatsAppClient(new MockHttpClient(), '', '123', 'v24.0'))->isConfigured());
    }
}
