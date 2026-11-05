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

use Phem\Stream\MemoryPublisher;
use Phem\Stream\SapiPublisher;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SapiPublisherTest extends TestCase
{
    public function testARequestOfFrankenPhpPublishesWithMercurePublish(): void
    {
        $frankenphp = new MemoryPublisher();
        $hub = new MemoryPublisher();

        new SapiPublisher($frankenphp, $hub, 'frankenphp')->publish(['phem:*'], '<turbo-stream></turbo-stream>', true);

        self::assertSame([['topics' => ['phem:*'], 'data' => '<turbo-stream></turbo-stream>', 'private' => true]], self::withoutTime($frankenphp));
        self::assertSame([], $hub->updates());
    }

    #[TestWith(['fpm-fcgi'], 'a request of PHP-FPM, with a standalone hub')]
    #[TestWith(['cli'], 'a Messenger worker or a command')]
    public function testAnotherSapiPublishesWithTheHttpApiOfTheHub(string $sapi): void
    {
        $frankenphp = new MemoryPublisher();
        $hub = new MemoryPublisher();

        new SapiPublisher($frankenphp, $hub, $sapi)->publish(['phem:*'], '<turbo-stream></turbo-stream>', false);

        self::assertSame([], $frankenphp->updates());
        self::assertSame([['topics' => ['phem:*'], 'data' => '<turbo-stream></turbo-stream>', 'private' => false]], self::withoutTime($hub));
    }

    /**
     * The updates with no publish time (see MemoryPublisher).
     *
     * @return list<array{topics: list<string>, data: string, private: bool}>
     */
    private static function withoutTime(MemoryPublisher $publisher): array
    {
        return array_map(static fn (array $update): array => ['topics' => $update['topics'], 'data' => $update['data'], 'private' => $update['private']], $publisher->updates());
    }
}
