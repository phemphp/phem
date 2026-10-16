<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The events of a page go to its URL: the access rules of the page apply to them, and a
 * request from another site is not an event (tests/Fixtures/app/src/pages/access.rhp).
 */
final class EventAccessTest extends TestCase
{
    use FixtureApp;

    public function testPathRuleOfThePageAppliesToItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/private/counter')->getStatusCode());
        self::assertSame(403, $this->increment('/private/counter', 'App\\Pages\\PrivateCounter')->getStatusCode(), 'a valid state for the page does not skip the rule');
    }

    public function testControllerAttributeOfThePageAppliesToThePageAndItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/denied')->getStatusCode());
        self::assertSame(403, $this->increment('/denied', 'App\\Pages\\DeniedPage')->getStatusCode());

        $response = $this->request('GET', '/cached');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'), '#[Cache] on a function page');
    }

    public function testEventFromAnotherSiteIsRejected(): void
    {
        self::assertSame(200, $this->increment('/leave', 'App\\Pages\\LeavePage')->getStatusCode());
        self::assertSame(200, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost'])->getStatusCode(), 'Origin, without Sec-Fetch-Site');
        self::assertSame(200, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => null])->getStatusCode(), 'not a browser');

        self::assertSame(403, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => 'same-site'])->getStatusCode(), 'a sibling subdomain');
        self::assertSame(403, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
        self::assertSame(403, $this->increment('/leave', 'App\\Pages\\LeavePage', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'null'])->getStatusCode());
    }

    public function testEventIsJson(): void
    {
        // A form of another site can send text/plain without a CORS preflight.
        self::assertSame(415, $this->increment('/leave', 'App\\Pages\\LeavePage', ['CONTENT_TYPE' => 'text/plain'])->getStatusCode());
    }

    public function testPostWithoutTheEventHeaderIsAPageRequest(): void
    {
        $response = $this->request('POST', '/about');
        self::assertSame(200, $response->getStatusCode(), 'the route of /about accepts all methods');
        self::assertStringContainsString('<p id="about">About</p>', (string) $response->getContent());

        $response = $this->request('POST', '/');
        self::assertSame(405, $response->getStatusCode(), 'the route of / accepts only GET and HEAD');
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
    }

    /**
     * Sends the first click handler of a page with a valid state for the page. The state comes
     * from the codec: a page behind an access rule does not render it.
     *
     * @param array<string, string|null> $server
     */
    private function increment(string $uri, string $page, array $server = []): Response
    {
        [$state, $signature] = $this->fixtureKernel()->getContainer()->get('test.service_container')->get('rephp.state_codec')->encode([], $page);
        $handler = 'rh'.substr(hash('xxh128', '/'.$page.'#0'), 0, 12).'.0';

        return $this->event($uri, ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click']], $server);
    }
}
