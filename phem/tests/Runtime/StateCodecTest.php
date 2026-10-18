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

use Phem\State\StateCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StateCodecTest extends TestCase
{
    private int $now = 1_000_000;

    public function testStateIsValidForItsPage(): void
    {
        $codec = $this->codec();
        [$json, $signature] = $codec->encode(['phem1' => ['n' => 1]], 'App\\A');

        self::assertSame('{"phem1":{"n":1}}', $json);
        self::assertMatchesRegularExpression('/^1000000\.[0-9a-f]{64}$/', $signature, 'the time it was issued, and the HMAC');
        self::assertSame(['phem1' => ['n' => 1]], $codec->decode($json, $signature, 'App\\A'));
        self::assertNull($codec->decode($json, $signature, 'App\\B'));
        self::assertNull($codec->decode('{"phem1":{"n":2}}', $signature, 'App\\A'));
    }

    public function testSignatureWithAnotherTimeIsNotValid(): void
    {
        $codec = $this->codec();
        [$json, $signature] = $codec->encode([], 'App\\A');

        self::assertNull($codec->decode($json, str_replace('1000000.', '1000500.', $signature), 'App\\A'), 'a newer time is forged');
        self::assertNull($codec->decode($json, substr($signature, 8), 'App\\A'), 'the HMAC alone (the format before the time)');
        self::assertNull($codec->decode($json, '', 'App\\A'));
    }

    public function testStateIsValidForItsMaximumAge(): void
    {
        $codec = $this->codec(maxAge: 60);
        [$json, $signature] = $codec->encode([], 'App\\A');

        $this->now += 60;
        self::assertSame([], $codec->decode($json, $signature, 'App\\A'));
        ++$this->now;
        self::assertNull($codec->decode($json, $signature, 'App\\A'), 'too old: the page reloads');

        $codec = $this->codec(maxAge: 0);
        [$json, $signature] = $codec->encode([], 'App\\A');
        $this->now += 10 * 365 * 86400;
        self::assertSame([], $codec->decode($json, $signature, 'App\\A'), '0: no limit');
    }

    public function testStateCanBeValidOnlyInItsSession(): void
    {
        $codec = $this->codec(bindSession: true);
        [$json, $signature] = $codec->encode([], 'App\\A', $this->request('s1'));

        self::assertSame([], $codec->decode($json, $signature, 'App\\A', $this->request('s1')));
        self::assertNull($codec->decode($json, $signature, 'App\\A', $this->request('s2')), 'another session');
        self::assertNull($codec->decode($json, $signature, 'App\\A', $this->request(null)), 'no session');

        // A page that starts the session: the next request has the cookie of the new session.
        $request = $this->request(null);
        $request->getSession()->setId('s3');
        $request->getSession()->start();
        [$json, $signature] = $codec->encode([], 'App\\A', $request);
        self::assertSame([], $codec->decode($json, $signature, 'App\\A', $this->request('s3')));
    }

    public function testStateIsNotBoundToTheSessionByDefault(): void
    {
        $codec = $this->codec();
        [$json, $signature] = $codec->encode([], 'App\\A', $this->request('s1'));

        self::assertSame([], $codec->decode($json, $signature, 'App\\A', $this->request('s2')));
    }

    private function codec(int $maxAge = 86400, bool $bindSession = false): StateCodec
    {
        return new StateCodec('secret', $maxAge, $bindSession, fn (): int => $this->now);
    }

    /** A request with the session cookie of a session id, or without a session cookie. */
    private function request(?string $sessionId): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/', cookies: null !== $sessionId ? [$session->getName() => $sessionId] : []);
        $request->setSession($session);

        return $request;
    }
}
