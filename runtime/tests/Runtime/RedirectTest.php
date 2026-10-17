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

use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase
{
    use FixtureApp;

    public function testHandlerThatReturnsARedirectResponseSendsItsUrl(): void
    {
        $html = (string) $this->request('GET', '/leave')->getContent();
        self::assertSame(1, preg_match('/data-mamu-state-value="([^"]*)" data-mamu-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/data-mamu-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/leave', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true), 'no streams and no state: the page does not render again');
    }
}
