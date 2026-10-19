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

use Phem\Handler\HandlerFingerprint;
use Phem\Http\EventPayload;
use Phem\State\StateCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;

/**
 * The events of a page go to its URL: the access rules of the page apply to them, and a
 * request from another site is not an event (tests/Fixtures/app/src/pages/access.phpm).
 */
final class EventAccessTest extends TestCase
{
    use FixtureApp;

    public function testPathRuleOfThePageAppliesToItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/private/counter')->getStatusCode());
        self::assertSame(403, $this->click('/private/counter', 'App\\Pages\\PrivateCounter', 'increment')->getStatusCode(), 'a valid state for the page does not skip the rule');
    }

    public function testControllerAttributeOfThePageAppliesToThePageAndItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/denied')->getStatusCode());
        self::assertSame(403, $this->click('/denied', 'App\\Pages\\DeniedPage', 'increment')->getStatusCode());

        $response = $this->request('GET', '/cached');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'), '#[Cache] on a page');
    }

    public function testRateLimitOfThePageAppliesToItsEvents(): void
    {
        if (!class_exists(RateLimit::class)) {
            self::markTestSkipped('The #[RateLimit] attribute needs Symfony 8.1.');
        }
        // The limit of the fixture app is 2 events in a minute.
        self::assertSame(200, $this->click('/limited', 'App\\Pages\\LimitedPage', 'increment')->getStatusCode());
        self::assertSame(200, $this->click('/limited', 'App\\Pages\\LimitedPage', 'increment')->getStatusCode());

        $response = $this->click('/limited', 'App\\Pages\\LimitedPage', 'increment');
        self::assertSame(429, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->request('GET', '/limited')->getStatusCode(), 'with `methods: \'POST\'`, the GET requests of the page have no limit');
        }
        self::assertSame(200, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave')->getStatusCode(), 'the limit is for each page (the key has the path)');
    }

    public function testEventFromAnotherSiteIsRejected(): void
    {
        self::assertSame(200, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave')->getStatusCode());
        self::assertSame(200, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost'])->getStatusCode(), 'Origin, without Sec-Fetch-Site');
        self::assertSame(200, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null])->getStatusCode(), 'not a browser');

        self::assertSame(403, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'same-site'])->getStatusCode(), 'a sibling subdomain');
        self::assertSame(403, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
        self::assertSame(403, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'null'])->getStatusCode());
    }

    public function testEventIsJson(): void
    {
        // A form of another site can send text/plain without a CORS preflight.
        self::assertSame(415, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', ['CONTENT_TYPE' => 'text/plain'])->getStatusCode());
    }

    public function testEventHasAMaximumSize(): void
    {
        // The fixture app has the default limit (1 MiB).
        $response = $this->event('/leave', ['handler' => 'h', 'event' => ['value' => str_repeat('a', 1048576)]]);
        self::assertSame(413, $response->getStatusCode());

        $response = $this->event('/leave', '{}', ['CONTENT_LENGTH' => '2000000']);
        self::assertSame(413, $response->getStatusCode(), 'the Content-Length header is enough');
    }

    public function testEventHasAMaximumJsonDepth(): void
    {
        self::assertSame(200, $this->click('/leave', 'App\\Pages\\LeavePage', 'leave', form: [['name', 'Ada']])->getStatusCode(), 'the form fields of the browser');

        $value = 'deep';
        for ($i = 0; $i < EventPayload::MAX_DEPTH; ++$i) {
            $value = [$value];
        }
        $response = $this->event('/leave', ['handler' => 'h', 'event' => ['value' => $value]]);
        self::assertSame(400, $response->getStatusCode());
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
     * Sends a click on a method of a page, with a valid state for the page. The state and the
     * handler id come from the runtime: a page behind an access rule does not render them.
     *
     * @param array<string, string|null>       $server
     * @param list<array{string, string}>|null $form   the form fields, as the browser sends them
     */
    private function click(string $uri, string $page, string $method, array $server = [], ?array $form = null): Response
    {
        [$state, $signature] = $this->service('phem.state_codec', StateCodec::class)->encode([], $page);
        // The handler is in the <Root> of the document (a template component, with no fiber).
        $handler = 'phem'.substr(hash('xxh128', '/'.$page.'#0/Phem\\Root#0'), 0, 12).'.'.new HandlerFingerprint()->of(new $page()->{$method}(...));

        return $this->event($uri, ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click', 'form' => $form]], $server);
    }
}
