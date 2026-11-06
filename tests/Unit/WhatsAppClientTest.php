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
