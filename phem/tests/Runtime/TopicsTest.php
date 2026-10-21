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

use Phem\Stream\Topics;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Size;
use Phem\Tests\Fixtures\Subscribed;
use PHPUnit\Framework\TestCase;

final class TopicsTest extends TestCase
{
    public function testAPageSubscribesToItselfToItsUrlAndToAllPages(): void
    {
        $topics = new Topics(secret: 's3cret');
        $page = $topics->ofPage(Subscribed::class, ['size' => 'l', 'id' => '42']);

        self::assertSame(['phem:'.Subscribed::class, 'phem:'.Subscribed::class.'?id=42&size=l', 'phem:*'], array_map($topics->name(...), $page));
    }

    public function testATopicIsAnHmacOfItsName(): void
    {
        [$topic, $scoped] = new Topics(secret: 's3cret')->ofPage(Subscribed::class, ['id' => '42', 'size' => 'l']);

        self::assertMatchesRegularExpression('/^phem:[A-Za-z0-9_-]{22}$/', $topic);
        self::assertStringNotContainsString('Subscribed', $topic.$scoped);
        self::assertStringNotContainsString('42', $scoped);
        self::assertNotSame($topic, new Topics(secret: 'other')->ofPage(Subscribed::class, [])[0], 'another secret');
        self::assertSame($topic, new Topics(secret: 's3cret')->ofPage(Subscribed::class, [])[0], 'the next request');
    }

    public function testAPageWithNoRouteValuesHasNoTopicOfAUrl(): void
    {
        $topics = new Topics();

        self::assertSame(['phem:'.Subscribed::class, 'phem:*'], array_map($topics->name(...), $topics->ofPage(Subscribed::class, [])));
    }

    public function testAPageWithNoSubscribeHasNoTopics(): void
    {
        self::assertSame([], new Topics()->ofPage(Scientist::class, ['id' => '1']));
    }

    public function testThePublisherGetsTheTopicOfThePageRender(): void
    {
        $topics = new Topics();

        self::assertSame($topics->ofPage(Subscribed::class, [])[0], $topics->to('\\'.Subscribed::class));
        // An int and a backed enum are the strings of the URL.
        self::assertSame($topics->ofPage(Subscribed::class, ['id' => '42', 'size' => 'l'])[1], $topics->to(Subscribed::class, ['size' => Size::Large, 'id' => 42]));
    }

    public function testThePublisherNeedsAPageWithSubscribe(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no #[Phem\Attribute\Subscribe] attribute');

        new Topics()->to(Scientist::class);
    }

    public function testThePublisherNeedsAllTheRouteValues(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('need all its #[Path] route values, but "size" is missing');

        new Topics()->to(Subscribed::class, ['id' => 42]);
    }

    public function testThePublisherNeedsTheRouteValuesOfThePage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no #[Path] prop "slug": give the route values of its #[Path] props ("id", "size")');

        new Topics()->to(Subscribed::class, ['slug' => 'x']);
    }

    public function testARouteValueIsNotAnObject(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The route value "id" of a topic is a "stdClass"');

        new Topics()->to(Subscribed::class, ['id' => new \stdClass(), 'size' => 'l']);
    }
}
