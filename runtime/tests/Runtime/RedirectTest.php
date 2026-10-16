<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase
{
    use FixtureApp;

    public function testHandlerThatReturnsARedirectResponseSendsItsUrl(): void
    {
        $html = (string) $this->request('GET', '/leave')->getContent();
        preg_match('/data-rhp-state-value="([^"]*)" data-rhp-signature-value="([^"]*)"/', $html, $m);
        preg_match('/data-rh-on-click="([^"]+)"/', $html, $h);

        $response = $this->request('POST', '/_rhp/event', json_encode([
            'page' => 'App\\Pages\\LeavePage',
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true), 'no streams and no state: the page does not render again');
    }
}
