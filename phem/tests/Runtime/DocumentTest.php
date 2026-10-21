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

use Phem\Http\PageRenderer;
use Phem\Rendering\Renderer;
use Phem\Root;
use Phem\State\PageState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Error\RuntimeError;

final class DocumentTest extends TestCase
{
    use FixtureApp;

    public function testThePageIsInAFullDocument(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">", $html);
        self::assertStringEndsWith('</html>', rtrim($html));
        self::assertSame(1, substr_count($html, '<title>'), 'one title, in <head>');
        self::assertSame(1, substr_count($html, 'id="phem-root"'));
    }

    public function testTheTitleIsText(): void
    {
        $html = (string) $this->request('GET', '/document/escaped')->getContent();

        self::assertStringContainsString('<title>Tom &amp; Jerry &lt;script&gt; 0</title>', $html, 'the title slot, with the state of the page');
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnEventDoesNotRenderTheDocument(): void
    {
        $html = (string) $this->request('GET', '/document/escaped')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/document/escaped', ['state' => html_entity_decode($m[1]), 'signature' => $m[2], 'handler' => $h[1], 'event' => ['type' => 'click']]);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(['streams', 'changes', 'signature', 'query', 'events'], array_keys($data));
        self::assertStringContainsString('<button id="increment" data-phem-on-click="'.$h[1].'">1</button>', $data['streams']);
        self::assertStringNotContainsString('<title', $data['streams']);
        self::assertStringNotContainsString('<html', $data['streams']);
        self::assertStringNotContainsString('phem-root', $data['streams'], 'the content of the root element, without the root element');
        self::assertSame(1, substr_count($data['streams'], '<turbo-stream'));
        self::assertStringContainsString('<title>Tom &amp; Jerry &lt;script&gt; 0</title>', (string) $this->request('GET', '/document/escaped')->getContent(), 'the server keeps no state');
    }

    public function testTheDocumentHasADefaultTitle(): void
    {
        self::assertStringContainsString('<title>Fixture</title>', (string) $this->request('GET', '/about')->getContent());
    }

    public function testComponentsOfTheDocumentRenderOutsideTheRoot(): void
    {
        $html = (string) $this->request('GET', '/document')->getContent();

        self::assertSame(1, preg_match('#<footer id="doc-footer">(.*?)</footer>#s', $html, $footer));
        self::assertStringContainsString('<span class="badge badge-info">ES</span>', $footer[1], 'a template component');
        self::assertStringContainsString('<button id="toggle" data-phem-on-click="', $footer[1], 'a class component renders');
        self::assertGreaterThan(strpos($html, '</main>'), strpos($html, '<footer id="doc-footer">'), 'outside the root element: the phem controller does not get its events');
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)"/', $html, $m));
        self::assertCount(2, PageState::fromArray(json_decode(html_entity_decode($m[1]), true))->fibers, 'the page and the toggle of the footer');
    }

    public function testAPageRendersARoot(): void
    {
        $pages = $this->service('phem.page_renderer', PageRenderer::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The page "App\Pages\NoRootPage" renders no <Root>: render it in a document component with `<Root>{{ children }}</Root>` (see "Phem\Root").');

        $pages->document('App\Pages\NoRootPage', Request::create('/document/none'));
    }

    public function testAPageRendersOneRoot(): void
    {
        $pages = $this->service('phem.page_renderer', PageRenderer::class);

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('The page "App\Pages\TwoRootsPage" renders more than one <Root>: render one, in the document of the page.');

        $pages->document('App\Pages\TwoRootsPage', Request::create('/document/two'));
    }

    public function testARootIsInAPage(): void
    {
        $renderer = $this->service('phem.renderer', Renderer::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A <Root> is the root element of a page: render it in the document of a page, not in a Twig template.');

        $renderer->component(Root::class, [], null, null, []);
    }
}
