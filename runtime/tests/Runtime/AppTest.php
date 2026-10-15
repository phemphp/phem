<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\App;
use Rephp\Server\Request;
use Rephp\StateCodec;

final class AppTest extends TestCase
{
    use FixtureApp;

    private App $app;
    private string $state;
    private string $signature;
    /** @var list<string> */
    private array $handlers;

    protected function setUp(): void
    {
        $this->app = new App(self::fixtureRuntime(), new StateCodec('test-secret'));
        $html = $this->app->handle(new Request('GET', '/'))->body;
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];
        preg_match_all('/data-rh-on-click="([^"]+)"/', $html, $h);
        $this->handlers = $h[1];
    }

    /** @return array{status: int, data: array<string, mixed>} */
    private function click(int $handler, ?string $state = null): array
    {
        $r = $this->app->handle(new Request('POST', App::EVENT_PATH, [], [], json_encode([
            'route' => '/',
            'state' => $state ?? $this->state,
            'signature' => $this->signature,
            'handler' => $this->handlers[$handler],
            'event' => ['type' => 'click'],
        ])));
        $data = json_decode($r->body, true);
        if (200 === $r->status) {
            $this->state = $data['state'];
            $this->signature = $data['signature'];
        }

        return ['status' => $r->status, 'data' => $data];
    }

    public function testPageRendersComponentsTemplatesAndSlots(): void
    {
        $html = $this->app->handle(new Request('GET', '/'))->body;
        self::assertStringContainsString('<title>Fixture Home</title>', $html);
        self::assertSame(2, substr_count($html, 'Clicked 0 times'));
        self::assertStringContainsString('style="color: magenta; font-weight: 700">Apple', $html);
        self::assertStringContainsString('<ol id="mapped"><li data-rh-key="1">Cabbage</li><li data-rh-key="3">Apple</li></ol>', $html);
        self::assertStringContainsString('<p>Body 0</p>', $html, 'children slot sees the caller variables');
        self::assertStringContainsString('<footer><span class="badge badge-info">FOOT</span></footer>', $html, 'named slot');
        self::assertStringContainsString('Hits 0 times !', $html, 'class component with children');
        self::assertStringContainsString('<em id="no">No</em>', $html);
        self::assertStringNotContainsString('id="and"', $html);
        self::assertStringContainsString('<p class="theme-dark">Theme: dark</p>', $html, 'Provider value');
        self::assertStringContainsString('<p class="theme-light">Theme: light</p>', $html, 'context default');
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

    public function testClassComponentUpdatesOnlyItsFiberAndClamps(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $r = $this->click(2);
        }
        self::assertSame(1, substr_count($r['data']['streams'], '<turbo-stream'));
        self::assertStringContainsString('Hits 3 times', $r['data']['streams']);
        // clamp() keeps 3: nothing changed, so no stream.
        $r = $this->click(2);
        self::assertSame('', $r['data']['streams']);
        self::assertStringContainsString('"count":3', $this->state);
    }

    public function testToggleAndReducer(): void
    {
        $r = $this->click(3);
        self::assertStringContainsString('<strong id="yes">Yes</strong>', $r['data']['streams']);
        self::assertStringContainsString('<span id="and">and-shown</span>', $r['data']['streams']);
        self::assertStringContainsString('ON', $r['data']['streams']);
        $this->click(4);
        $r = $this->click(4);
        self::assertStringContainsString('<span id="items">item1,item2</span>', $r['data']['streams']);
    }

    public function testForgedStateIsRejected(): void
    {
        $r = $this->click(0, str_replace('[0,', '[99,', $this->state));
        self::assertSame(409, $r['status']);
        self::assertTrue($r['data']['reload']);
    }

    public function testUnknownRouteIsNotHandled(): void
    {
        self::assertNull($this->app->handle(new Request('GET', '/nope')));
    }
}
