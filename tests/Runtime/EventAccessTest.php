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
use Phem\Http\EventBody;
use Phem\Http\EventPayload;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;

/**
 * The events of a page go to its URL: the access rules of the page apply to them, and a
 * request from another site is not an event (tests/Fixtures/app/src/page/access.phpm).
 */
final class EventAccessTest extends TestCase
{
    use FixtureApp;

    public function testPathRuleOfThePageAppliesToItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/private/counter')->getStatusCode());
        self::assertSame(403, $this->click('/private/counter', 'App\\Page\\PrivateCounter', 'increment')->getStatusCode(), 'a valid state for the page does not skip the rule');
    }

    public function testControllerAttributeOfThePageAppliesToThePageAndItsEvents(): void
    {
        self::assertSame(403, $this->request('GET', '/denied')->getStatusCode());
        self::assertSame(403, $this->click('/denied', 'App\\Page\\DeniedPage', 'increment')->getStatusCode());

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
        self::assertSame(200, $this->click('/limited', 'App\\Page\\LimitedPage', 'increment')->getStatusCode());
        self::assertSame(200, $this->click('/limited', 'App\\Page\\LimitedPage', 'increment')->getStatusCode());

        $response = $this->click('/limited', 'App\\Page\\LimitedPage', 'increment');
        self::assertSame(429, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->request('GET', '/limited')->getStatusCode(), 'with `methods: \'POST\'`, the GET requests of the page have no limit');
        }

        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave')->getStatusCode(), 'the limit is for each page (the key has the path)');
    }

    public function testRateLimitOfAHandlerAppliesToItsEventsOnly(): void
    {
        if (!class_exists(RateLimit::class)) {
            self::markTestSkipped('The #[RateLimit] attribute needs Symfony 8.1.');
        }

        // The limit of the fixture app is 2 events in a minute.
        self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'search')->getStatusCode());
        self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'search')->getStatusCode());

        $response = $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'search');
        self::assertSame(429, $response->getStatusCode(), 'the handler does not run');
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'increment')->getStatusCode(), 'a handler without the attribute has no limit');
        }

        self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'heavy')->getStatusCode(), 'the key has the method: each handler has its own count');
        self::assertSame(429, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'heavy')->getStatusCode(), 'with `tokens: 2`, one event uses all the limit');

        self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'like')->getStatusCode());
        self::assertSame(200, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'unlike')->getStatusCode());
        self::assertSame(429, $this->click('/limited-handler', 'App\\Page\\LimitedHandlerPage', 'like')->getStatusCode(), 'an Expression key and a Closure key with the same value share one count');
    }

    /**
     * An event can send only the signature: the server finds the state in the cache. On a miss,
     * it answers 409 before the handler, and the browser sends the event again with its state.
     */
    public function testEventCanSendOnlyTheSignature(): void
    {
        $this->service(StateCache::POOL, CacheItemPoolInterface::class)->clear();
        $payload = $this->clickPayload('App\\Page\\LeavePage', 'leave');
        $state = $payload['state'];
        unset($payload['state']);

        $response = $this->event('/leave', $payload);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"state":"required"}', $response->getContent(), 'the cache does not have the state');

        // The state of the page is small: the app does not cache it, but the server finds any entry.
        $cache = new StateCache($this->service(StateCache::POOL, CacheItemPoolInterface::class), 0);
        $cache->save($payload['signature'], $state);
        $response = $this->event('/leave', $payload);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('redirect', (string) $response->getContent(), 'the handler runs with the state of the cache');

        $cache->save($payload['signature'], '{"phem1":{"n":1}}');
        self::assertSame('{"reload":true}', $this->event('/leave', $payload)->getContent(), 'a cache entry that does not fit the signature');

        $payload['signature'] = '1.'.str_repeat('a', 64);
        self::assertSame('{"reload":true}', $this->event('/leave', $payload)->getContent(), 'a signature older than state_max_age reloads, with no lookup');

        $json = json_encode(['signature' => $payload['signature']] + $payload, \JSON_THROW_ON_ERROR);
        self::assertSame(400, $this->multipart('/leave', ['photo' => self::file('a')], json: $json)->getStatusCode(), 'an event with files sends its state');
    }

    public function testEventThatMissesTheCacheDoesNotRunItsMiddlewares(): void
    {
        if (!class_exists(RateLimit::class)) {
            self::markTestSkipped('The #[RateLimit] attribute needs Symfony 8.1.');
        }

        $this->service(StateCache::POOL, CacheItemPoolInterface::class)->clear();
        $payload = $this->clickPayload('App\\Page\\LimitedHandlerPage', 'search');
        $full = $payload;
        unset($payload['state']);

        // The limit of the fixture app is 2 events in a minute.
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(409, $this->event('/limited-handler', $payload)->getStatusCode());
        }

        self::assertSame(200, $this->event('/limited-handler', $full)->getStatusCode(), 'a miss uses no token of the limit of the handler');
        self::assertSame(200, $this->event('/limited-handler', $full)->getStatusCode());
        self::assertSame(429, $this->event('/limited-handler', $full)->getStatusCode());
    }

    public function testEventFromAnotherSiteIsRejected(): void
    {
        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave')->getStatusCode());
        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost'])->getStatusCode(), 'Origin, without Sec-Fetch-Site');
        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null])->getStatusCode(), 'not a browser');

        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'same-site'])->getStatusCode(), 'a sibling subdomain');
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'null'])->getStatusCode());
    }

    public function testEventIsJson(): void
    {
        // A form of another site can send text/plain without a CORS preflight.
        self::assertSame(415, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => 'text/plain'])->getStatusCode());
    }

    /**
     * Live Components had a CSRF bypass in 2026: the check read the `Accept` header, which a
     * request of another site can set. Phem reads the `Content-Type` header, and a request of
     * another site can send only text/plain, a URL-encoded form or multipart/form-data without
     * a CORS preflight.
     */
    public function testNoOtherHeaderOrContentTypeSkipsTheCsrfChecks(): void
    {
        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => 'application/json; charset=utf-8'])->getStatusCode());

        self::assertSame(415, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'])->getStatusCode(), 'the Accept header is not the content type');
        self::assertSame(415, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => 'text/plain; application/json'])->getStatusCode(), 'a parameter is not the content type');
        self::assertSame(415, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])->getStatusCode());
        self::assertSame(415, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['CONTENT_TYPE' => ''])->getStatusCode(), 'no content type');

        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'none'])->getStatusCode(), 'a URL that the user typed, or a bookmark');
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://localhost'])->getStatusCode(), 'Sec-Fetch-Site comes before Origin');
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost:8080'])->getStatusCode(), 'another port');
        self::assertSame(403, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost.evil.example'])->getStatusCode(), 'a host that starts with the host of the page');

        // A form of another site cannot send the X-Phem-Event header: the request is a page request.
        $response = $this->click('/leave', 'App\\Page\\LeavePage', 'leave', ['HTTP_X_PHEM_EVENT' => null, 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('redirect', (string) $response->getContent(), 'the handler does not run');
    }

    public function testEventHasAMaximumSize(): void
    {
        // The fixture app has the default limit (1 MiB).
        $response = $this->event('/leave', ['handler' => 'h', 'event' => ['value' => str_repeat('a', 1048576)]]);
        self::assertSame(413, $response->getStatusCode());

        $response = $this->event('/leave', '{}', ['CONTENT_LENGTH' => '2000000']);
        self::assertSame(413, $response->getStatusCode(), 'the Content-Length header is enough');
    }

    /**
     * The browser compresses a large body with gzip. The limit is also for the JSON after the
     * server unzips it, so a small body that unzips to a very large one (a "zip bomb") is 413.
     */
    public function testEventBodyCanBeGzip(): void
    {
        $json = json_encode($this->clickPayload('App\\Page\\LeavePage', 'leave'), \JSON_THROW_ON_ERROR);
        self::assertSame(200, $this->event('/leave', (string) gzencode($json), ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode());
        self::assertSame(200, $this->event('/leave', (string) gzencode($json), ['HTTP_CONTENT_ENCODING' => ' GZIP '])->getStatusCode(), 'the encoding has no case');
        self::assertSame(200, $this->event('/leave', $json, ['HTTP_CONTENT_ENCODING' => 'identity'])->getStatusCode());

        $bomb = (string) gzencode(json_encode(['handler' => 'h', 'event' => ['value' => str_repeat('a', 1048576)]], \JSON_THROW_ON_ERROR), 9);
        self::assertLessThan(10000, \strlen($bomb));
        self::assertSame(413, $this->event('/leave', $bomb, ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode(), 'the unzipped JSON has the limit of phem.max_event_size');
        self::assertSame(413, $this->event('/leave', (string) gzencode('{}'), ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_LENGTH' => '2000000'])->getStatusCode(), 'the compressed body has the limit too');

        self::assertSame(400, $this->event('/leave', $json, ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode(), 'a body that is not gzip');
        self::assertSame(400, $this->event('/leave', substr((string) gzencode($json), 0, -12), ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode(), 'a body that is not complete');
        self::assertSame(400, $this->event('/leave', '', ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode(), 'an empty body');

        self::assertSame(415, $this->event('/leave', $json, ['HTTP_CONTENT_ENCODING' => 'br'])->getStatusCode());
        self::assertSame(415, $this->event('/leave', $json, ['HTTP_CONTENT_ENCODING' => 'gzip, gzip'])->getStatusCode());
        self::assertSame(415, $this->multipart('/leave', ['photo' => self::file('a')], ['HTTP_CONTENT_ENCODING' => 'gzip'])->getStatusCode(), 'an event with files is not compressed');
    }

    /**
     * An event with files is multipart/form-data. A form of another site can send it without a
     * CORS preflight, but not with the X-Phem-Event header: the same checks apply.
     */
    public function testEventWithFilesIsMultipart(): void
    {
        self::assertSame(200, $this->multipart('/leave', ['photo' => self::file('a')])->getStatusCode());
        self::assertSame(200, $this->multipart('/leave', [], ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=y'])->getStatusCode(), 'the media type has no case');

        self::assertSame(403, $this->multipart('/leave', ['photo' => self::file('a')], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $this->multipart('/leave', ['photo' => self::file('a')], ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
        self::assertSame(415, $this->multipart('/leave', ['photo' => self::file('a')], ['CONTENT_TYPE' => 'multipart/mixed; boundary=x'])->getStatusCode());

        $response = $this->multipart('/leave', ['photo' => self::file('a')], ['HTTP_X_PHEM_EVENT' => null, 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(200, $response->getStatusCode(), 'with no X-Phem-Event header, it is a page request');
        self::assertStringNotContainsString('redirect', (string) $response->getContent(), 'the handler does not run');
    }

    public function testFilesOfAnEventHaveAMaximumSize(): void
    {
        // The fixture app has the default limit (8 MiB).
        $large = self::file('');
        self::resize($large, 8388609);
        self::assertSame(413, $this->multipart('/leave', ['photos' => [self::file('a'), $large]])->getStatusCode());
        self::resize($large, 8388608);
        self::assertSame(200, $this->multipart('/leave', ['photos' => [$large]])->getStatusCode(), 'a size of the limit');

        $tooLargeForPhp = new UploadedFile(__FILE__, 'a.txt', null, \UPLOAD_ERR_INI_SIZE, true);
        self::assertSame(200, $this->multipart('/leave', ['photo' => $tooLargeForPhp, 'photos' => [$large]])->getStatusCode(), 'the handler gets a file over upload_max_filesize of PHP, with its error, and it does not count');

        $request = Request::create('/leave', 'POST', server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame(413, $this->fixtureKernel()->handle($request)->getStatusCode(), 'post_max_size of PHP drops all the parts');

        $response = $this->multipart('/leave', ['photo' => self::file('a')], json: json_encode(['handler' => 'h', 'event' => ['value' => str_repeat('a', 1048576)]], \JSON_THROW_ON_ERROR));
        self::assertSame(413, $response->getStatusCode(), 'the JSON part has the limit of phem.max_event_size');
    }

    public function testEventHasAMaximumJsonDepth(): void
    {
        self::assertSame(200, $this->click('/leave', 'App\\Page\\LeavePage', 'leave', form: [['name', 'Ada']])->getStatusCode(), 'the form fields of the browser');

        $value = 'deep';
        for ($i = 0; $i < EventPayload::MAX_DEPTH; ++$i) {
            $value = [$value];
        }

        $response = $this->event('/leave', ['handler' => 'h', 'event' => ['value' => $value]]);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testOnlyALoadEventHasAListOfHandlers(): void
    {
        $load = ['type' => 'load', 'value' => null, 'checked' => null, 'key' => null, 'form' => null];

        self::assertSame(400, $this->event('/leave', ['handler' => ['a', 'b'], 'event' => ['type' => 'click'] + $load])->getStatusCode(), 'a click');
        self::assertSame(400, $this->event('/leave', ['handler' => [], 'event' => $load])->getStatusCode(), 'no handler');
        self::assertSame(400, $this->event('/leave', ['handler' => ['a', 1], 'event' => $load])->getStatusCode(), 'not a string');
        self::assertSame(400, $this->event('/leave', ['handler' => ['b' => 'a'], 'event' => $load])->getStatusCode(), 'not a list');
        self::assertSame(400, $this->event('/leave', ['handler' => array_fill(0, EventPayload::MAX_LOADS + 1, 'a'), 'event' => $load])->getStatusCode(), 'too many');
    }

    public function testLoadEventRendersAllItsBoundariesInOneResponse(): void
    {
        $html = (string) $this->request('GET', '/bench/dashboard')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(4, preg_match_all('/data-phem-load="([^"]+)"/', $html, $loads));
        $page = ['state' => html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5), 'signature' => html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5), 'event' => ['type' => 'load', 'value' => null, 'checked' => null, 'key' => null, 'form' => null], 'bound' => new \stdClass()];

        $response = $this->event('/bench/dashboard', ['handler' => $loads[1]] + $page);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['Sales', 'Orders', 'Users', 'Stock'] as $name) {
            self::assertStringContainsString($name.': 3 rows', $data['streams']);
        }

        self::assertStringNotContainsString('data-phem-load', $data['streams']);

        $response = $this->event('/bench/dashboard', ['handler' => [$loads[1][0], 'phem000000000000.x']] + $page);
        self::assertSame(409, $response->getStatusCode(), 'a handler that the page does not have');
        self::assertSame('{"reload":true}', $response->getContent());
    }

    public function testPostWithoutTheEventHeaderIsAPageRequest(): void
    {
        $response = $this->request('POST', '/about');
        self::assertSame(200, $response->getStatusCode(), 'the route of /about accepts all methods');
        self::assertMatchesRegularExpression('#<p id="about" data-phem-c="phem[0-9a-f]{12}">About</p>#', (string) $response->getContent());

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
        return $this->event($uri, $this->clickPayload($page, $method, $form), $server);
    }

    /**
     * Sends a click on the `leave` handler of LeavePage as multipart/form-data, with files.
     *
     * @param array<string, mixed>       $files  name => UploadedFile, or a list of them
     * @param array<string, string|null> $server more headers; null removes a default
     * @param string|null                $json   the JSON part, instead of the click
     */
    private function multipart(string $uri, array $files, array $server = [], ?string $json = null): Response
    {
        $json ??= json_encode($this->clickPayload('App\\Page\\LeavePage', 'leave'), \JSON_THROW_ON_ERROR);
        $server += ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        $request = Request::create($uri, 'POST', [EventBody::PART => $json], [], $files, array_filter($server, static fn (?string $v): bool => null !== $v));

        $kernel = $this->fixtureKernel();
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * The JSON data of a click on a method of a page, with a valid state for the page.
     *
     * @param list<array{string, string}>|null $form the form fields, as the browser sends them
     *
     * @return array<string, mixed>
     */
    private function clickPayload(string $page, string $method, ?array $form = null): array
    {
        [$state, $signature] = $this->service('phem.state_codec', StateCodec::class)->encode([], $page);
        // The handler is in the <Root> of the document (a template component, with no fiber).
        $handler = 'phem'.substr(hash('xxh128', '/'.$page.'#0/Phem\\Root#0'), 0, 12).'.'.new HandlerFingerprint()->of(new $page()->{$method}(...));

        return ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click', 'form' => $form]];
    }

    /** @param int<0, max> $size */
    private static function resize(UploadedFile $file, int $size): void
    {
        $handle = fopen($file->getPathname(), 'r+');
        self::assertNotFalse($handle);
        ftruncate($handle, $size);
        fclose($handle);
        clearstatcache();
    }

    private static function file(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phem');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'a.txt', null, \UPLOAD_ERR_OK, true);
    }
}
