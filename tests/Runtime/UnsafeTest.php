<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Data in a page cannot add HTML or script (tests/Fixtures/app/src/page/unsafe.phpm).
 */
final class UnsafeTest extends TestCase
{
    use FixtureApp;

    public function testStringThatAComponentReturnsIsText(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;</phem-c>', $html);
        self::assertStringNotContainsString('<b>Ada</b>', $html);
    }

    public function testSpreadOfDataAddsNoAttributeAndNoScript(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        self::assertStringContainsString('<a onclick="track()" id="spread" title="ok">link</a>', $html, 'the static onclick stays; the invalid name and the onclick of the data are not rendered');
    }

    /**
     * A `javascript:` URL from data runs as a script when the user clicks the element. React
     * blocks it in a URL attribute (`href`, `src`, `action`, `formAction`, ...), also with
     * another case, spaces at the ends, or a tab in the scheme, as the browser parses the URL.
     */
    public function testUrlOfDataRunsNoScript(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();

        foreach (['website' => 'href', 'quoted' => 'href', 'link' => 'href', 'action' => 'formaction'] as $id => $attribute) {
            self::assertSame(1, preg_match('/<[a-z]+ id="'.$id.'"[^>]*>/', $html, $m), $id);
            if (1 !== preg_match('/\s'.$attribute.'="([^"]*)"/', $m[0], $value)) {
                continue;
            }

            $url = str_replace(["\t", "\n", "\r"], '', trim(html_entity_decode($value[1], \ENT_QUOTES | \ENT_HTML5), "\x00..\x20"));
            self::assertDoesNotMatchRegularExpression('/^javascript:/i', $url, \sprintf('the %s of #%s is a script: %s', $attribute, $id, $m[0]));
        }
    }

    public function testDataInTheTagOfAChildComponentIsText(): void
    {
        $html = (string) $this->request('GET', '/unsafe')->getContent();
        self::assertSame(1, preg_match('/<div id="children">.*?<\/div>\s*<\/main>/s', $html, $m));
        $children = $m[0];
        $xss = '&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;';

        self::assertStringNotContainsString('<script', $children);
        self::assertStringContainsString('<h2>'.$xss.'</h2>', $children, 'a prop of a template component');
        self::assertStringContainsString('<p>'.$xss.'</p>', $children, 'the children slot');
        self::assertStringContainsString('<footer><span class="tag">'.$xss.'</span></footer>', $children, 'a named slot');
        self::assertStringContainsString('<span data-phem-key="'.$xss.'" class="badge badge-'.$xss.'">', $children, 'the key and a prop in an attribute');
        self::assertStringContainsString('IT&#039;S \ #{XSS} &quot;&gt;&lt;SCRIPT&gt;ALERT(3)&lt;/SCRIPT&gt;</span>', $children, 'a quoted prop: the literal is not a Twig string with interpolation');
        self::assertStringContainsString($xss.' 0 times '.$xss, $children, 'a prop and the children of a class component');
        self::assertSame(1, preg_match('/<phem-c id="phem[0-9a-f]{12}"><button data-phem-on-click="phem[0-9a-f]{12}\.[0-9a-f]+" data-count="0">/', $children), 'the key of a class component is a hash in the fiber id and the handler id');
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
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/<button id="go" data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/unsafe', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click', 'value' => $url],
        ]);

        return ['status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getContent(), true)];
    }
}
