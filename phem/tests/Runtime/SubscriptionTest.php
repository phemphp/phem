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

use Phem\Stream\SubscriberToken;
use Phem\Stream\Subscription;
use Phem\Stream\Topics;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Subscribed;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SubscriptionTest extends TestCase
{
    private const KEY = 'a-subscriber-key-of-32-bytes-at-least';
    private const HUB = 'https://example.com/.well-known/mercure';

    public function testTheRootElementGetsTheUrlOfTheHubWithTheTopics(): void
    {
        $topics = new Topics();
        $subscription = new Subscription($topics);
        [$page] = $topics->ofPage(Subscribed::class, []);

        self::assertSame(
            'data-phem-stream-value="/.well-known/mercure?match='.rawurlencode($page).'&amp;match=phem%3A%2A"',
            $subscription->attribute(Subscribed::class, [], Request::create('/')),
        );
        self::assertSame('', $subscription->attribute(Scientist::class, [], Request::create('/')));
    }

    public function testTheCookieHasATokenForTheTopicsOfThePage(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $request = Request::create('https://example.com/guestbook');
        $subscription->attribute(Subscribed::class, [], $request);

        $cookie = $subscription->cookie($request);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('__Secure-mercure_access_token', $cookie->getName());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame(new Topics()->ofPage(Subscribed::class, []), $tokens->topics((string) $cookie->getValue(), self::HUB, ''));
    }

    public function testTheTokenKeepsTheTopicsOfTheCookieOfAnotherTab(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $old = $tokens->create($tokens->grant(['phem:App\Other', 'phem:*']), self::HUB, self::subject('s1'));
        $request = self::withSession('s1', ['__Secure-mercure_access_token' => $old]);
        $subscription->renew(Subscribed::class, [], $request);

        $cookie = $subscription->cookie($request);
        self::assertNotNull($cookie);
        self::assertSame([...new Topics()->ofPage(Subscribed::class, []), 'phem:App\Other'], $tokens->topics((string) $cookie->getValue(), self::HUB, self::subject('s1')));
    }

    /**
     * A topic of the old cookie did not pass the access rules of this request: the viewer can
     * have lost access to its page since (another role, another owner). It keeps its expiry, so
     * an event on another page with #[Subscribe] does not extend it, and the viewer does not get
     * the private pushes of the page with no end. The token expires with its first topic.
     */
    public function testAKeptTopicKeepsItsExpiry(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $expiry = time() + 50000;
        $old = $tokens->create(['phem:App\Revoked' => $expiry], self::HUB, self::subject('s1'));
        $request = self::withSession('s1', ['__Secure-mercure_access_token' => $old]);
        $subscription->renew(Subscribed::class, [], $request);

        $cookie = $subscription->cookie($request);
        self::assertInstanceOf(Cookie::class, $cookie);
        $token = (string) $cookie->getValue();
        $grants = $tokens->grants($token, self::HUB, self::subject('s1'));
        self::assertSame($expiry, $grants['phem:App\Revoked'] ?? null, 'the kept topic gets no new expiry');
        self::assertSame($expiry, self::expiry($token), 'the token expires with its first topic');
        foreach (new Topics()->ofPage(Subscribed::class, []) as $topic) {
            self::assertGreaterThan($expiry, $grants[$topic] ?? 0, 'a topic of the page gets a new expiry');
        }
    }

    public function testAKeptTopicThatLivedHalfOfItsTimeGoes(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $old = $tokens->create(['phem:App\Revoked' => time() + 10], self::HUB, self::subject('s1'));
        $request = self::withSession('s1', ['__Secure-mercure_access_token' => $old]);
        $subscription->renew(Subscribed::class, [], $request);

        $cookie = $subscription->cookie($request);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame(new Topics()->ofPage(Subscribed::class, []), $tokens->topics((string) $cookie->getValue(), self::HUB, self::subject('s1')));
    }

    private static function expiry(string $token): int
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true), true);

        return \is_array($claims) && \is_int($claims['exp'] ?? null) ? $claims['exp'] : 0;
    }

    public function testTheTokenDropsTheTopicsOfTheCookieOfAnotherSession(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $old = $tokens->create($tokens->grant(['phem:App\Inbox?user=5', 'phem:*']), self::HUB, self::subject('alice'));
        $request = self::withSession('bob', ['__Secure-mercure_access_token' => $old]);
        $subscription->renew(Subscribed::class, [], $request);

        $cookie = $subscription->cookie($request);
        self::assertNotNull($cookie);
        self::assertSame(new Topics()->ofPage(Subscribed::class, []), $tokens->topics((string) $cookie->getValue(), self::HUB, self::subject('bob')));
    }

    public function testAFreshTokenWithTheTopicsIsNotRenewed(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $subscription = new Subscription(new Topics(), $tokens);
        $fresh = $tokens->create($tokens->grant(new Topics()->ofPage(Subscribed::class, [])), self::HUB, '');
        $request = Request::create('https://example.com/guestbook', cookies: ['__Secure-mercure_access_token' => $fresh]);
        $subscription->renew(Subscribed::class, [], $request);
        self::assertNull($subscription->cookie($request));

        $old = $tokens->create(array_fill_keys(new Topics()->ofPage(Subscribed::class, []), time() + 10), self::HUB, '');
        $request = Request::create('https://example.com/guestbook', cookies: ['__Secure-mercure_access_token' => $old]);
        $subscription->renew(Subscribed::class, [], $request);
        self::assertNotNull($subscription->cookie($request), 'it lived more than half of its time');
    }

    public function testTheCookieIsSecureOnHttpsAndOnLocalhostOnly(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $subscription = new Subscription(new Topics(), new SubscriberToken(self::KEY), logger: $logger);
        $cookie = static function (string $url) use ($subscription): ?Cookie {
            $request = Request::create($url);
            $subscription->renew(Subscribed::class, [], $request);

            return $subscription->cookie($request);
        };

        self::assertTrue($cookie('http://localhost:8080/')?->isSecure());
        self::assertSame([], $logger->messages);
        self::assertFalse($cookie('http://app.test/')?->isSecure());
        self::assertStringContainsString('set phem.mercure.cookie_name', implode(' ', $logger->messages));
    }

    private static function subject(string $session): string
    {
        return hash('xxh128', $session);
    }

    /** @param array<string, string> $cookies */
    private static function withSession(string $id, array $cookies): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('https://example.com/guestbook', cookies: $cookies + [$session->getName() => $id]);
        $request->setSession($session);

        return $request;
    }

    public function testNoCookieForPublicUpdatesOrForAPageWithNoSubscribe(): void
    {
        $request = Request::create('https://example.com/');
        $public = new Subscription(new Topics());
        $public->renew(Subscribed::class, [], $request);
        self::assertNull($public->cookie($request));

        self::assertNull(new Subscription(new Topics(), new SubscriberToken(self::KEY))->cookie(Request::create('https://example.com/')));
    }

    public function testAnInvalidTokenHasNoTopics(): void
    {
        $tokens = new SubscriberToken(self::KEY);
        $token = $tokens->create($tokens->grant(['phem:*']), self::HUB);

        self::assertSame(['phem:*'], $tokens->topics($token, self::HUB));
        self::assertSame([], $tokens->topics($token, 'https://other.example/.well-known/mercure'));
        self::assertSame([], new SubscriberToken('another-subscriber-key-of-32-bytes')->topics($token, self::HUB));
        self::assertSame([], new SubscriberToken(self::KEY, 'another')->topics($token, self::HUB));
        self::assertSame([], $tokens->topics($tokens->create(['phem:*' => time() - 1], self::HUB), self::HUB));
        self::assertSame([], $tokens->topics('not.a.token', self::HUB));
    }

    public function testTheTokenIsAnAccessTokenOfTheModernMercureProtocol(): void
    {
        [$header, $claims] = array_map(static fn (string $part): mixed => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true), \array_slice(explode('.', new SubscriberToken(self::KEY)->create(['phem:*' => time() + 60], self::HUB)), 0, 2));

        self::assertSame(['alg' => 'HS256', 'typ' => 'at+jwt'], $header);
        self::assertIsArray($claims);
        self::assertSame('phem', $claims['iss']);
        self::assertSame(self::HUB, $claims['aud']);
        self::assertSame([['type' => 'https://mercure.rocks/authorization-detail', 'actions' => ['subscribe'], 'topics' => [['match' => 'phem:*']]]], $claims['authorization_details']);
        self::assertGreaterThan(time(), $claims['exp']);
    }
}
