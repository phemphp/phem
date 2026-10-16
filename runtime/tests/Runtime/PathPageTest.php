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
        self::assertSame(['id' => '3'], json_decode($state, true)['_path']);

        $response = $this->click('/items/3', $state, $signature, $handler);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Item 3: 1', $data['streams']);
        self::assertSame(['id' => '3'], json_decode($data['state'], true)['_path']);
    }

    public function testForgedRouteValueIsRejected(): void
    {
        [$state, $signature, $handler] = $this->page('/items/3');
        $forged = str_replace('"_path":{"id":"3"}', '"_path":{"id":"4"}', $state);
        self::assertNotSame($state, $forged);

        self::assertSame(409, $this->click('/items/3', $forged, $signature, $handler)->getStatusCode());
    }

    public function testStateOfAnotherUrlOfThePageIsRejected(): void
    {
        [$state, $signature, $handler] = $this->page('/items/3');

        self::assertSame(409, $this->click('/items/4', $state, $signature, $handler)->getStatusCode(), 'the access rules of /items/4 do not cover /items/3');
    }

    public function testMappedRouteValueFindsTheEntity(): void
    {
        self::assertStringContainsString('>Ada: 0</button>', (string) $this->request('GET', '/scientists/1')->getContent());
        self::assertStringContainsString('>Marie: 0</button>', (string) $this->request('GET', '/scientists/by-name/Marie')->getContent());
    }

    public function testRouteValueOfAnEntityThatDoesNotExistIsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/scientists/9')->getStatusCode());
    }

    public function testNullableEntityGetsNull(): void
    {
        self::assertStringContainsString('<p id="maybe">Marie</p>', (string) $this->request('GET', '/maybe/2')->getContent());
        self::assertStringContainsString('<p id="maybe">Nobody</p>', (string) $this->request('GET', '/maybe/9')->getContent());
    }

    public function testEventFindsTheEntityAgain(): void
    {
        [$state, $signature, $handler] = $this->page('/scientists/1');
        self::assertSame(['scientist' => ['id' => '1']], json_decode($state, true)['_path'], 'the criteria, not the entity');

        $response = $this->click('/scientists/1', $state, $signature, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ada: 1', json_decode((string) $response->getContent(), true)['streams']);
    }

    /** @return array{0: string, 1: string, 2: string} the state, its signature, and the click handler */
    private function page(string $uri): array
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        preg_match('/data-rh-on-click="([^"]+)"/', $html, $h);

        return [html_entity_decode($m[1]), $m[2], $h[1]];
    }

    private function click(string $uri, string $state, string $signature, string $handler): Response
    {
        return $this->event($uri, [
            'state' => $state,
            'signature' => $signature,
            'handler' => $handler,
            'event' => ['type' => 'click'],
        ]);
    }
}
