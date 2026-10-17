<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use Mamu\Http\PageRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentTest extends TestCase
{
    use FixtureApp;

    public function testThePageIsInAFullDocument(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">", $html);
        self::assertStringEndsWith('</html>', rtrim($html));
        self::assertSame(1, substr_count($html, '<title>'), 'one title, in <head>');
        self::assertSame(1, substr_count($html, 'id="mamu-root"'));
    }

    public function testTheTitleIsText(): void
    {
        $html = (string) $this->request('GET', '/document/escaped')->getContent();

        self::assertStringContainsString('<title>Tom &amp; Jerry &lt;script&gt; 0</title>', $html, 'the title() of the parent, and the state of the page');
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnEventDoesNotRenderTheDocument(): void
    {
        $html = (string) $this->request('GET', '/document/escaped')->getContent();
        self::assertSame(1, preg_match('/data-mamu-state-value="([^"]*)" data-mamu-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/data-mamu-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/document/escaped', ['state' => html_entity_decode($m[1]), 'signature' => $m[2], 'handler' => $h[1], 'event' => ['type' => 'click']]);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(['streams', 'state', 'signature'], array_keys($data));
        self::assertStringContainsString('<button id="increment" data-mamu-on-click="'.$h[1].'">1</button>', $data['streams']);
        self::assertStringNotContainsString('<title', $data['streams']);
        self::assertStringNotContainsString('<html', $data['streams']);
        self::assertStringContainsString('<title>Tom &amp; Jerry &lt;script&gt; 0</title>', (string) $this->request('GET', '/document/escaped')->getContent(), 'the server keeps no state');
    }

    public function testComponentsOfTheDocumentAreNotInThePage(): void
    {
        $html = (string) $this->request('GET', '/document')->getContent();

        self::assertSame(1, preg_match('#<footer id="doc-footer">(.*?)</footer>#s', $html, $footer));
        self::assertStringContainsString('<span class="badge badge-info">ES</span>', $footer[1], 'a template component');
        self::assertStringContainsString('<button id="toggle" data-mamu-on-click="', $footer[1], 'a class component renders');
        self::assertGreaterThan(strpos($html, '</main>'), strpos($html, '<footer id="doc-footer">'), 'outside the root element: the mamu controller does not get its events');
        self::assertSame(1, preg_match('/data-mamu-state-value="([^"]*)"/', $html, $m));
        self::assertCount(1, json_decode(html_entity_decode($m[1]), true), 'only the page is in the state');
    }

    public function testOnlyADocumentCanBeAPage(): void
    {
        $pages = $this->service('mamu.page_renderer', PageRenderer::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The page "App\Pages\Toggle" must be a class that implements "Mamu\Document": make it extend the document of the application.');

        $pages->document('App\Pages\Toggle', Request::create('/'));
    }
}
