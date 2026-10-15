<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\EventListener\SourceMapListener;
use Rephp\Manifest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
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
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-rh-on-click="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];
    }

    /** @return array{status: int, data: array<string, mixed>} */
    private function click(int $handler, ?string $state = null): array
    {
        $response = $this->request('POST', '/_rhp/event', json_encode([
            'route' => '/',
            'state' => $state ?? $this->state,
            'signature' => $this->signature,
            'handler' => $this->handlers[$handler],
            'event' => ['type' => 'click'],
        ]));
        $data = json_decode((string) $response->getContent(), true);
        if (200 === $response->getStatusCode()) {
            $this->state = $data['state'];
            $this->signature = $data['signature'];
        }

        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    public function testPageRendersComponentsTemplatesAndSlots(): void
    {
        $response = $this->request('GET', '/');
        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Fixture Home</title>', $html);
        self::assertStringContainsString('<script type="module" src="/bundles/rephp/rhp.js"></script>', $html);
        self::assertStringContainsString('data-rhp-endpoint-value="/_rhp/event"', $html);
        self::assertSame(2, substr_count($html, 'Clicked 0 times'));
        self::assertStringContainsString('style="color: magenta; font-weight: 700">Apple', $html);
        self::assertStringContainsString('<ol id="mapped"><li data-rh-key="1">Cabbage</li><li data-rh-key="3">Apple</li></ol>', $html);
        self::assertStringContainsString('<p>Body 0</p>', $html, 'children slot sees the caller variables');
        self::assertStringContainsString('<footer><span class="badge badge-info">FOOT</span></footer>', $html, 'named slot');
        self::assertStringContainsString('Hits 0 times !', $html, 'class component with children');
        self::assertStringContainsString('<em id="no">No</em>', $html);
        self::assertStringNotContainsString('id="and"', $html);
        self::assertStringContainsString('<p class="theme-dark">Theme: dark</p>', $html, 'template component prop');
        self::assertStringContainsString('<p class="theme-light">Theme: light</p>', $html, 'template component default');
        self::assertStringContainsString('<p class="greeting">Hello, ADA!</p>', $html, 'function component');
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

    public function testBadEventAndUnknownRoute(): void
    {
        self::assertSame(400, $this->request('POST', '/_rhp/event', '{}')->getStatusCode());
        self::assertSame(405, $this->request('GET', '/_rhp/event')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/nope')->getStatusCode());
    }

    public function testComponentInAPlainTwigTemplate(): void
    {
        $twig = $this->fixtureKernel()->getContainer()->get('test.service_container')->get('twig');
        $html = $twig->createTemplate("{% component 'App\\\\Pages\\\\Greeting' with {name: 'twig'} %}")->render();

        self::assertMatchesRegularExpression('#^<rh-c id="rh[0-9a-f]+"><p class="greeting">Hello, TWIG!</p></rh-c>$#', $html);
    }

    public function testTwigErrorsPointToTheRhpSource(): void
    {
        /** @var Manifest $manifest */
        $manifest = $this->fixtureKernel()->getContainer()->get('rephp.manifest');
        $error = new \RuntimeException('outer', 0, new RuntimeError('Boom.', 3, new Source('', '@rhp/pages/index.6.twig')));

        (new SourceMapListener($manifest))->map($error);

        $twigError = $error->getPrevious();
        self::assertSame('src/pages/index.rhp', $twigError->getSourceContext()->getName());
        self::assertSame($manifest->sourceDir().'/pages/index.rhp', $twigError->getFile());
        $templateLine = (require $manifest->file())['templates']['pages/index.6.twig']['line'];
        self::assertSame($templateLine + 2, $twigError->getLine(), 'line 3 of the template');
    }

    public function testRenderCommandPrintsAPage(): void
    {
        $tester = new CommandTester((new Application($this->fixtureKernel()))->find('rephp:render'));

        self::assertSame(0, $tester->execute(['path' => '/']));
        self::assertStringContainsString('<title>Fixture Home</title>', $tester->getDisplay());
    }
}
