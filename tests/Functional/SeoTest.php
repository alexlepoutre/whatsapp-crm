<?php

namespace App\Tests\Functional;

class SeoTest extends AppTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public/';

    public function testHomeHasPreviewAndStructuredData(): void
    {
        $crawler = $this->client->request('GET', '/?organisation=itroom');

        self::assertSelectorExists('meta[name="description"]');
        self::assertSelectorExists('meta[property="og:image"][content$="og-image.png?v=1"]');
        self::assertSelectorExists('meta[name="twitter:card"][content="summary_large_image"]');
        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));

        $data = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        $types = array_column($data['@graph'], '@type');
        self::assertContains('FAQPage', $types);

        $faq = $data['@graph'][array_search('FAQPage', $types, true)];
        self::assertCount($crawler->filter('.l-faq details')->count(), $faq['mainEntity']);
    }

    public function testOrganizationNameIsNotEscapedTwice(): void
    {
        $this->createOrganization('dupont', 'Dupont & Fils');

        $crawler = $this->client->request('GET', '/dupont?organisation=dupont');

        self::assertSame('Contacter Dupont & Fils', $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('http://localhost/dupont', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testPrivatePagesHaveNoPreview(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSelectorNotExists('link[rel="canonical"]');

        $this->loginAsOwner();
        $this->client->request('GET', '/admin');
        self::assertSelectorNotExists('link[rel="canonical"]');
    }

    public function testSitemapOnlyListsIndexablePages(): void
    {
        $xml = simplexml_load_file(self::PUBLIC_DIR.'sitemap.xml');
        self::assertNotFalse($xml);
        self::assertNotEmpty($xml->url);

        foreach ($xml->url as $url) {
            $this->client->request('GET', (string) parse_url((string) $url->loc, \PHP_URL_PATH));

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('meta[name="robots"]');
            self::assertSelectorExists('link[rel="canonical"]');
        }
    }

    public function testRobotsProtectsPrivatePages(): void
    {
        $robots = (string) file_get_contents(self::PUBLIC_DIR.'robots.txt');

        self::assertStringContainsString('Disallow: /*/suivi/', $robots);
        self::assertStringContainsString('Disallow: /admin/', $robots);
        self::assertStringContainsString('Sitemap: https://ma-relation-clients.fr/sitemap.xml', $robots);
    }
}
