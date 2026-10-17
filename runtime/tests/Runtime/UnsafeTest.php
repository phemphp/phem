<?php

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Data in a page cannot add HTML or script (tests/Fixtures/app/src/pages/unsafe.phpm).
 */
final class UnsafeTest extends TestCase
{
    use FixtureApp;

    public function testStringThatAComponentReturnsIsText(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;</mamu-c>', $html);
        self::assertStringNotContainsString('<b>Ada</b>', $html);
    }

    public function testSpreadOfDataAddsNoAttributeAndNoScript(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        self::assertStringContainsString('<a onclick="track()" id="spread" title="ok">link</a>', $html, 'the static onclick stays; the invalid name and the onclick of the data are not rendered');
    }

    public function testHandlerRedirectsOnlyToARelativeOrAnHttpUrl(): void
    {
        self::assertSame(['redirect' => '/about'], $this->go('/about')['data']);
        self::assertSame(['redirect' => 'https://example.com/a'], $this->go('https://example.com/a')['data']);

        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>'] as $url) {
            self::assertSame(500, $this->go($url)['status'], $url);
        }
    }

    /** @return array{status: int, data: mixed} */
    private function go(string $url): array
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();
        self::assertSame(1, preg_match('/data-mamu-state-value="([^"]*)" data-mamu-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/<button id="go" data-mamu-on-click="([^"]+)"/', $html, $h));
        $response = $this->event('/unsafe', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click', 'value' => $url],
        ]);

        return ['status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getContent(), true)];
    }
}
