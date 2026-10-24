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

final class RedirectTest extends TestCase
{
    use FixtureApp;

    public function testHandlerThatReturnsARedirectResponseSendsItsUrl(): void
    {
        $html = (string) $this->request('GET', '/leave')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/leave', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true), 'no streams and no state: the page does not render again');
    }

    public function testHandlerThatReturnsARedirectSendsTheUrlOfThePage(): void
    {
        self::assertSame(['redirect' => '/about'], $this->json('/go', '#about'), 'a function page');
        self::assertSame(['redirect' => '/items/5?tab=notes'], $this->json('/go', '#item'), 'a class page, with a route value and the query string');
    }

    public function testHandlerThatRedirectsDropsItsBrowserEvents(): void
    {
        $response = $this->click('/notify', '#leave');

        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true));
    }

    public function testResponseHasTheBrowserEventsOfTheHandler(): void
    {
        $data = json_decode((string) $this->click('/notify', '#save')->getContent(), true);

        self::assertIsArray($data);
        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Saved 1']], ['name' => 'saved', 'detail' => 1]], $data['events']);
        self::assertSame([], json_decode((string) $this->click('/notify', '#quiet')->getContent(), true)['events'] ?? null);
    }

    public function testResponseHasTheBrowserEventsOfTheInjectedDispatcher(): void
    {
        $data = json_decode((string) $this->click('/notify', '#announce')->getContent(), true);

        self::assertIsArray($data);
        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Announced 1']], ['name' => 'announced', 'detail' => 'Hello']], $data['events']);
    }

    /** @return array<string, mixed> */
    private function json(string $uri, string $id): array
    {
        $response = $this->click($uri, $id);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function click(string $uri, string $id): \Symfony\Component\HttpFoundation\Response
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/id="'.substr($id, 1).'" data-phem-on-click="([^"]+)"/', $html, $h));
        // DEBUG

        return $this->event($uri, [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);
    }
}
