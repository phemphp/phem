<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PathPageTest extends TestCase
{
    use FixtureApp;

    public function testClassPageGetsTheRouteValue(): void
    {
        $response = $this->request('GET', '/items/3');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('>Item 3: 0</button>', (string) $response->getContent());
    }

    public function testFunctionPageGetsTheRouteValueOrItsDefault(): void
    {
        self::assertStringContainsString('<p id="hello">Hello Ada</p>', (string) $this->request('GET', '/hello/Ada')->getContent());
        self::assertStringContainsString('<p id="hello">Hello World</p>', (string) $this->request('GET', '/hello')->getContent());
    }

    public function testRouteValueThatDoesNotMatchItsTypeIsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/items/abc')->getStatusCode());
    }

    public function testEventKeepsTheRouteValue(): void
    {
        [$state, $signature, $handler] = $this->page('/items/3');
        self::assertSame(['id' => 3], json_decode($state, true)['_props']);

        $response = $this->click($state, $signature, $handler);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Item 3: 1', $data['streams']);
        self::assertSame(['id' => 3], json_decode($data['state'], true)['_props']);
    }

    public function testForgedRouteValueIsRejected(): void
    {
        [$state, $signature, $handler] = $this->page('/items/3');
        $forged = str_replace('"_props":{"id":3}', '"_props":{"id":4}', $state);
        self::assertNotSame($state, $forged);

        self::assertSame(409, $this->click($forged, $signature, $handler)->getStatusCode());
    }

    /** @return array{0: string, 1: string, 2: string} the state, its signature, and the click handler */
    private function page(string $uri): array
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        preg_match('/data-rh-on-click="([^"]+)"/', $html, $h);

        return [html_entity_decode($m[1]), $m[2], $h[1]];
    }

    private function click(string $state, string $signature, string $handler): Response
    {
        return $this->request('POST', '/_rhp/event', json_encode([
            'page' => 'App\\Pages\\ItemPage',
            'state' => $state,
            'signature' => $signature,
            'handler' => $handler,
            'event' => ['type' => 'click'],
        ]));
    }
}
