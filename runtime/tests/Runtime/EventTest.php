<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Event;
use Symfony\Component\HttpFoundation\Request;

final class EventTest extends TestCase
{
    public function testRequestHasTheFormFieldsAsPostData(): void
    {
        $request = Request::create('/_rhp/event', 'POST', content: '{"handler":"h"}');
        $event = Event::fromArray(['type' => 'submit', 'form' => ['name' => 'Ada', 'profession' => 'mathematician']], $request);

        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $event->form);
        self::assertNotSame($request, $event->request);
        self::assertSame('POST', $event->request->getMethod());
        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $event->request->request->all());
        self::assertSame([], $request->request->all());
    }

    public function testRequestNestsTheFieldsOfANamedForm(): void
    {
        $request = Request::create('/_rhp/event', 'POST');
        $event = Event::fromArray(['type' => 'submit', 'form' => ['scientist[name]' => 'Ada', 'scientist[profession]' => 'mathematician']], $request);

        self::assertSame(['scientist[name]' => 'Ada', 'scientist[profession]' => 'mathematician'], $event->form);
        self::assertSame(['scientist' => ['name' => 'Ada', 'profession' => 'mathematician']], $event->request->request->all());
    }

    public function testRequestIsNullWithoutAnHttpRequest(): void
    {
        $event = Event::fromArray(['type' => 'click']);

        self::assertSame([], $event->form);
        self::assertNull($event->request);
    }
}
