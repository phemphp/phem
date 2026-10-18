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

use Mamu\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventTest extends TestCase
{
    public function testRequestHasTheFormFieldsAsPostData(): void
    {
        $request = Request::create('/_mamu/event', 'POST', content: '{"handler":"h"}');
        $event = Event::fromArray(['type' => 'submit', 'form' => ['name' => 'Ada', 'profession' => 'mathematician']], $request);

        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $event->form);
        self::assertNotNull($event->request);
        self::assertNotSame($request, $event->request);
        self::assertSame('POST', $event->request->getMethod());
        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $event->request->request->all());
        self::assertSame([], $request->request->all());
    }

    public function testRequestNestsTheFieldsOfANamedForm(): void
    {
        $request = Request::create('/_mamu/event', 'POST');
        $event = Event::fromArray(['type' => 'submit', 'form' => ['scientist[name]' => 'Ada', 'scientist[profession]' => 'mathematician']], $request);

        self::assertSame(['scientist[name]' => 'Ada', 'scientist[profession]' => 'mathematician'], $event->form);
        self::assertNotNull($event->request);
        self::assertSame(['scientist' => ['name' => 'Ada', 'profession' => 'mathematician']], $event->request->request->all());
    }

    public function testFieldPairsKeepEachValueOfAName(): void
    {
        $request = Request::create('/_mamu/event', 'POST');
        $event = Event::fromArray(['type' => 'submit', 'form' => [['tags[]', 'a'], ['tags[]', 'b'], ['role', 'x'], ['role', 'y'], ['action', 'delete']]], $request);

        self::assertSame(['tags[]' => ['a', 'b'], 'role' => ['x', 'y'], 'action' => 'delete'], $event->form);
        self::assertNotNull($event->request);
        self::assertSame(['tags' => ['a', 'b'], 'role' => 'y', 'action' => 'delete'], $event->request->request->all(), 'as PHP parses a POST body');
    }

    public function testRequestIsNullWithoutAnHttpRequest(): void
    {
        $event = Event::fromArray(['type' => 'click']);

        self::assertSame([], $event->form);
        self::assertNull($event->request);
    }
}
