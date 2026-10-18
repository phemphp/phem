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

use Phem\EventListener\SourceMapListener;
use Phem\Manifest\Manifest;
use Phem\Manifest\SourceMap;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Source;

final class AppTest extends TestCase
{
    use FixtureApp;

    private string $state;
    private string $signature;
    /** @var list<string> */
    private array $handlers;

    protected function setUp(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-phem-on-click="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];
    }

    /** @return array{status: int, data: array<string, mixed>} */
    private function click(int $handler, ?string $state = null, string $uri = '/'): array
    {
        $response = $this->event($uri, [
            'state' => $state ?? $this->state,
            'signature' => $this->signature,
            'handler' => $this->handlers[$handler],
            'event' => ['type' => 'click'],
        ]);
        $data = json_decode((string) $response->getContent(), true);
        if (200 === $response->getStatusCode()) {
            $this->state = $data['state'];
            $this->signature = $data['signature'];
        }

        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    /** Loads a page, with its state and its click handlers for click(). */
    private function open(string $uri): string
    {
        $response = $this->request('GET', $uri);
        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-phem-on-click="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];

        return $html;
    }

    public function testPageRendersComponentsTemplatesAndSlots(): void
    {
        $response = $this->request('GET', '/');
        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">", $html);
        self::assertStringContainsString('<title>Fixture Home</title>', $html, 'the page composes the document, and gives its title');
        self::assertStringContainsString('<body class="fixture"><div id="phem-root" data-controller="phem" data-action="click->phem#dispatch', html_entity_decode($html));
        self::assertStringNotContainsString('data-phem-page-value', $html, 'the page comes from the route of the event');
        self::assertSame(2, substr_count($html, 'Clicked 0 times'));
        self::assertStringContainsString('style="color: magenta; font-weight: 700">Apple', $html);
        self::assertStringContainsString('<ol id="mapped"><li data-phem-key="1">Cabbage</li><li data-phem-key="3">Apple</li></ol>', $html);
        self::assertStringContainsString('<p>Body 0</p>', $html, 'children slot sees the caller variables');
        self::assertStringContainsString('<footer><span class="badge badge-info">FOOT</span></footer>', $html, 'named slot');
        self::assertStringContainsString('Hits 0 times !', $html, 'class component with children');
        self::assertStringContainsString('<em id="no">No</em>', $html);
        self::assertStringNotContainsString('id="and"', $html);
        self::assertStringContainsString('<p class="theme-dark">Theme: dark</p>', $html, 'template component prop');
        self::assertStringContainsString('<p class="theme-light">Theme: light</p>', $html, 'template component default');
        self::assertStringContainsString('<p class="greeting">Hello, ADA!</p>', $html, 'function component');
        self::assertStringContainsString('<span class="tag">untagged</span>', $html, 'slot default without children');
        self::assertStringContainsString('<span class="tag">urgent</span>', $html, 'slot default with children');
        self::assertStringContainsString('<span id="items">item1</span>', $html, 'mount() sets the first state');
        self::assertCount(5, $this->handlers);
    }

    public function testLiftedStateUpdatesBothButtons(): void
    {
        $r = $this->click(0);
        self::assertSame(200, $r['status']);
        self::assertSame(2, substr_count($r['data']['streams'], 'Clicked 1 times'));
        self::assertStringNotContainsString('<title>', $r['data']['streams']);
        $r = $this->click(1);
        self::assertSame(2, substr_count($r['data']['streams'], 'Clicked 2 times'));
    }

    public function testRestPropsSpreadOnAnElement(): void
    {
        $html = (string) $this->request('GET', '/rest')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-phem-on-(?:click|dblclick)="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];

        self::assertMatchesRegularExpression('#<button type="submit" data-phem-on-click="[^"]+" id="a" data-role="counter" aria-label="Count">Clicked 0 times</button>#', $html, 'a template component; the spread replaces type');
        self::assertMatchesRegularExpression('#<button class="override" data-phem-on-dblclick="[^"]+">HI</button>#', $html, 'a function component; the spread replaces className');
        self::assertStringContainsString('<button type="button" id="forwarded">Clicked 7 times</button>', $html, 'rest props forwarded to a component');
        self::assertStringContainsString('<button type="button">Clicked 0 times</button>', $html, 'no rest props');
        self::assertMatchesRegularExpression('#<a class="primary" href="/" data-phem-on-click="[^"]+">Home 0</a>#', $html, 'a class component; the rest prop replaces className');
        self::assertStringContainsString('<a class="link">Plain</a>', $html, 'a class component without rest props');
        self::assertCount(3, $this->handlers);

        $r = $this->click(0, uri: '/rest');
        self::assertSame(200, $r['status']);
        self::assertSame(2, substr_count($r['data']['streams'], 'Clicked 1 times'), 'onClick in the rest props');
        $r = $this->click(1, uri: '/rest');
        self::assertSame(2, substr_count($r['data']['streams'], 'Clicked 2 times'), 'onDoubleClick in the rest props');
        $r = $this->click(2, uri: '/rest');
        self::assertStringContainsString('Home 3', $r['data']['streams'], 'onClick in the rest prop of a class component');
    }

    public function testSlotsListsTitlesAndReadonlyProps(): void
    {
        $html = (string) $this->request('GET', '/edge')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-phem-on-click="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];

        self::assertStringContainsString('<title>Fixture</title>', $html, 'the <title> of an SVG is not the title of the page');
        self::assertStringContainsString('<svg id="icon"><title>Icon</title></svg>', $html);
        self::assertStringContainsString('<p id="body">Body</p>', $html, 'a required slot of a template component');
        self::assertStringContainsString('<footer>Foot 0</footer>', $html, 'the template tests a given slot');
        self::assertSame(1, substr_count($html, '<footer>'), 'no footer without the slot');
        self::assertStringContainsString('<ul id="odd"><li data-phem-key="1">1</li><li data-phem-key="3">3</li></ul>', $html, 'a list of markup with null items');
        self::assertStringContainsString('<span id="label">n=0</span>', $html);
        self::assertMatchesRegularExpression('#<h3 id="heading" data-phem-on-click="[^"]+">Edge 0</h3>#', $html, 'the members of a parent class outside the sources');
        self::assertStringContainsString('<p id="path">/edge</p>', $html, 'the app global of Twig');

        $r = $this->click(0, uri: '/edge');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('<span id="label">n=1</span>', $r['data']['streams'], 'a readonly prop gets the new value of the second pass');
        self::assertStringContainsString('<footer>Foot 1</footer>', $r['data']['streams']);

        $r = $this->click(1, uri: '/edge');
        self::assertStringContainsString('Edge 1</h3>', $r['data']['streams'], 'a handler of the parent class');
    }

    public function testAReadonlySlotKeepsTheFiberOrder(): void
    {
        $html = $this->open('/edge/readonly-slot');
        self::assertMatchesRegularExpression('#<div id="shelf"><phem-c id="phem[0-9a-f]+"><button data-phem-on-click="[^"]+">own 0</button></phem-c><phem-c id="phem[0-9a-f]+"><button data-phem-on-click="[^"]+">slot 0</button></phem-c></div>#', $html);

        $r = $this->click(0, uri: '/edge/readonly-slot');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('own 1', $r['data']['streams'], 'the click goes to the chip of the shelf');
        self::assertStringNotContainsString('slot 1', $r['data']['streams']);
    }

    public function testANewInstanceForAReadonlyPropIsMounted(): void
    {
        self::assertStringContainsString('<span id="gauge">n0</span>', $this->open('/edge/mount'));

        $r = $this->click(0, uri: '/edge/mount');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('<span id="gauge">n1</span>', $r['data']['streams']);
    }

    public function testTheSlotsOfAParentClassOutsideTheSources(): void
    {
        self::assertStringContainsString('<div id="boxed"><b>kid</b>|f</div>', $this->open('/edge/open-slots'));
    }

    public function testASlotThatATemplateTestsRendersOnce(): void
    {
        $html = $this->open('/edge/if-slot');
        self::assertSame(1, substr_count($html, 'foot 0'));

        preg_match_all('/<phem-c id="(phem[0-9a-f]+)">/', $html, $ids);
        $state = json_decode($this->state, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSame([], array_values(array_diff(array_keys($state), $ids[1])), 'each fiber of the state is in the page');
    }

    public function testAListWithNullItemsIsAnArrayProp(): void
    {
        self::assertStringContainsString('<p id="count">2</p>', $this->open('/edge/list-prop'));
    }

    public function testClassComponentUsesItsInjectedService(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $r = $this->click(2);
        }
        self::assertSame(1, substr_count($r['data']['streams'], '<turbo-stream'));
        self::assertStringContainsString('Hits 3 times', $r['data']['streams']);
        // Limits::$max is 3: nothing changed, so no stream.
        $r = $this->click(2);
        self::assertSame('', $r['data']['streams']);
        self::assertStringContainsString('"count":3', $this->state);
    }

    public function testToggleAndMountRunsOnlyWithoutState(): void
    {
        $r = $this->click(3);
        self::assertStringContainsString('<strong id="yes">Yes</strong>', $r['data']['streams']);
        self::assertStringContainsString('<span id="and">and-shown</span>', $r['data']['streams']);
        self::assertStringContainsString('ON', $r['data']['streams']);
        $this->click(4);
        $r = $this->click(4);
        self::assertStringContainsString('<span id="items">item1,item2,item3</span>', $r['data']['streams']);
    }

    public function testForgedStateIsRejected(): void
    {
        $r = $this->click(0, str_replace('"count":0', '"count":99', $this->state));
        self::assertSame(409, $r['status']);
        self::assertTrue($r['data']['reload']);
    }

    public function testStateIsSignedForItsPage(): void
    {
        $r = $this->click(0, uri: '/about');
        self::assertSame(409, $r['status']);
        self::assertTrue($r['data']['reload']);
    }

    public function testBadEventAndUnknownRoute(): void
    {
        self::assertSame(400, $this->event('/', '{}')->getStatusCode());
        self::assertSame(404, $this->event('/_phem/event', '{}')->getStatusCode(), 'no shared event route');
        self::assertSame(405, $this->request('POST', '/')->getStatusCode(), 'the methods of the #[Route], without the event header');
        self::assertSame(404, $this->request('GET', '/nope')->getStatusCode());
    }

    public function testRouteAttributesExposeThePages(): void
    {
        $router = $this->service('router', RouterInterface::class);

        self::assertSame('/', $router->generate('home'), 'the name of the #[Route]');
        self::assertSame('/about', $router->generate('app_pages_about'), 'the default name');
        self::assertSame('/about-us', $router->generate('about_us'), 'a repeated #[Route]');

        foreach (['/about', '/about-us'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<p id="about">About</p>', (string) $response->getContent(), 'a function component page');
        }
    }

    public function testAPageChoosesItsDocument(): void
    {
        $html = html_entity_decode((string) $this->request('GET', '/document')->getContent());

        self::assertStringContainsString('<html lang="es">', $html, 'another document component');
        self::assertStringContainsString('<title>Documento 3</title>', $html, 'the title slot of the page, with its state');
        self::assertMatchesRegularExpression('#<body>\s*<main id="phem-root" data-controller="phem extra" data-action="click->phem\#dispatch [^"]*" data-phem-state-value="\{"phem[0-9a-f]+":\{"count":3\},"phem[0-9a-f]+":\{"on":false\}\}" data-phem-signature-value="[^"]*" class="custom page"><phem-c id="phem[0-9a-f]+">\s*<p id="document">Document</p>\s*</phem-c></main>\s*<footer id="doc-footer">#', $html);

        $html = (string) $this->request('GET', '/about')->getContent();
        self::assertStringContainsString('<title>Fixture</title>', $html, 'a page with the title of the document');
        self::assertStringContainsString('<body class="fixture">', $html);
    }

    public function testAssetMapperHasTheClient(): void
    {
        $assets = $this->service('asset_mapper', AssetMapperInterface::class);

        $controller = $assets->getAsset('phemjs/phem_controller.js');
        self::assertNotNull($controller);
        self::assertStringEndsWith('/assets/dist/phem_controller.js', $controller->sourcePath);
        self::assertNotNull($assets->getAsset('phemjs/phem.css'));

        $map = $this->service('stimulus.asset_mapper.controllers_map_generator', ControllersMapGenerator::class)->getControllersMap();
        self::assertArrayHasKey('phem', $map, 'the name of the controller in assets/package.json');
        self::assertSame($controller->sourcePath, $map['phem']->asset->sourcePath);
        self::assertFalse($map['phem']->isLazy);
        self::assertStringEndsWith('/assets/dist/phem.css', $map['phem']->autoImports[0]->path);
    }

    public function testComponentInAPlainTwigTemplate(): void
    {
        $twig = $this->service('twig', Environment::class);
        $html = $twig->createTemplate("{% component 'App\\\\Pages\\\\Greeting' with {name: 'twig'} %}")->render();

        self::assertMatchesRegularExpression('#^<phem-c id="phem[0-9a-f]+"><p class="greeting">Hello, TWIG!</p></phem-c>$#', $html);
    }

    public function testAFunctionWithoutTheComponentAttributeIsAPlainFunction(): void
    {
        /** @var Manifest $manifest */
        $manifest = $this->fixtureKernel()->getContainer()->get('phem.manifest');

        self::assertNotNull($manifest->component('App\\Pages\\Greeting'));
        self::assertNull($manifest->component('App\\Pages\\Shout'), 'no #[Component]');
        self::assertTrue(\function_exists('App\\Pages\\Shout'), 'the compiled file declares the function');
    }

    public function testTwigErrorsPointToThePhpmSource(): void
    {
        /** @var Manifest $manifest */
        $manifest = $this->fixtureKernel()->getContainer()->get('phem.manifest');
        $error = new \RuntimeException('outer', 0, new RuntimeError('Boom.', 3, new Source('', '@phpm/pages/index.6.twig')));

        (new SourceMapListener(new SourceMap($manifest)))->map($error);

        $twigError = $error->getPrevious();
        self::assertInstanceOf(RuntimeError::class, $twigError);
        self::assertNotNull($twigError->getSourceContext());
        self::assertSame('src/pages/index.phpm', $twigError->getSourceContext()->getName());
        self::assertSame($manifest->sourceDir().'/pages/index.phpm', $twigError->getFile());
        $templateLine = (require $manifest->file())['templates']['pages/index.6.twig']['line'];
        self::assertSame($templateLine + 2, $twigError->getLine(), 'line 3 of the template');
    }

    public function testRenderCommandPrintsAPage(): void
    {
        $tester = new CommandTester((new Application($this->fixtureKernel()))->find('phem:render'));

        self::assertSame(0, $tester->execute(['path' => '/']));
        self::assertStringStartsWith('<!DOCTYPE html>', $tester->getDisplay());
        self::assertStringContainsString('<title>Fixture Home</title>', $tester->getDisplay());
    }
}
