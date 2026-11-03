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

use Phem\Form\TouchedFields;
use Phem\Http\EventRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class EventRequestTest extends TestCase
{
    public function testRequestHasTheFormFieldsAsPostData(): void
    {
        $request = Request::create('/_phem/event', 'POST', content: '{"handler":"h"}');
        $copy = EventRequest::of($request, ['type' => 'submit', 'form' => ['name' => 'Ada', 'profession' => 'mathematician']]);

        self::assertNotSame($request, $copy);
        self::assertSame('POST', $copy->getMethod());
        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $copy->request->all());
        self::assertSame([], $request->request->all());
    }

    public function testRequestNestsTheFieldsOfANamedForm(): void
    {
        $copy = EventRequest::of(Request::create('/_phem/event', 'POST'), ['type' => 'submit', 'form' => ['scientist[name]' => 'Ada', 'scientist[profession]' => 'mathematician']]);

        self::assertSame(['scientist' => ['name' => 'Ada', 'profession' => 'mathematician']], $copy->request->all());
    }

    public function testFieldPairsAreParsedAsAPostBody(): void
    {
        $copy = EventRequest::of(Request::create('/_phem/event', 'POST'), ['type' => 'submit', 'form' => [['tags[]', 'a'], ['tags[]', 'b'], ['role', 'x'], ['role', 'y'], ['action', 'delete']]]);

        self::assertSame(['tags' => ['a', 'b'], 'role' => 'y', 'action' => 'delete'], $copy->request->all(), 'as PHP parses a POST body');
    }

    public function testAnEventOfAFormHasItsTouchedFields(): void
    {
        $change = EventRequest::of(Request::create('/_phem/event', 'POST'), ['type' => 'input', 'form' => [['account[email]', 'ada']], 'touched' => ['account[email]']]);
        $submit = EventRequest::of(Request::create('/_phem/event', 'POST'), ['type' => 'submit', 'form' => [['account[email]', 'ada']]]);
        $click = EventRequest::of(Request::create('/_phem/event', 'POST'), ['type' => 'click', 'form' => null]);

        $touched = $change->attributes->get(TouchedFields::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(TouchedFields::class, $touched);
        self::assertTrue($touched->touches('account[email]'));
        self::assertFalse($touched->touches('account[city]'));
        self::assertTrue($submit->attributes->get(TouchedFields::REQUEST_ATTRIBUTE)?->isAll());
        self::assertFalse($click->attributes->has(TouchedFields::REQUEST_ATTRIBUTE), 'an event with no form');
    }

    public function testRequestKeepsTheFilesAndTheAttributes(): void
    {
        $request = Request::create('/_phem/event', 'POST');
        $request->attributes->set('_route', 'page');
        $request->files->set('photo', new UploadedFile(__FILE__, 'a.txt', test: true));

        $copy = EventRequest::of($request, ['type' => 'click']);

        self::assertSame('page', $copy->attributes->get('_route'));
        self::assertTrue($copy->files->has('photo'));
    }
}
