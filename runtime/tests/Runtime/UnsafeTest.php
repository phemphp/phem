<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Data in a page cannot add HTML or script (tests/Fixtures/app/src/pages/unsafe.rhp).
 */
final class UnsafeTest extends TestCase
{
    use FixtureApp;

    public function testStringThatAComponentReturnsIsText(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;</rh-c>', $html);
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
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        preg_match('/<button id="go" data-rh-on-click="([^"]+)"/', $html, $h);
        $response = $this->event('/unsafe', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click', 'value' => $url],
        ]);

        return ['status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getContent(), true)];
    }
}
