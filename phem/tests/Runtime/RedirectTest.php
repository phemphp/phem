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

    private function click(string $uri, string $id): \Symfony\Component\HttpFoundation\Response
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/id="'.substr($id, 1).'" data-phem-on-click="([^"]+)"/', $html, $h));

        return $this->event($uri, [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);
    }
}
