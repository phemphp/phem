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

use Phem\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testFormHasTheFields(): void
    {
        $event = Event::fromArray(['type' => 'submit', 'form' => ['name' => 'Ada', 'profession' => 'mathematician']]);

        self::assertSame('submit', $event->type);
        self::assertSame(['name' => 'Ada', 'profession' => 'mathematician'], $event->form);
    }

    public function testFieldPairsKeepEachValueOfAName(): void
    {
        $event = Event::fromArray(['type' => 'submit', 'form' => [['tags[]', 'a'], ['tags[]', 'b'], ['role', 'x'], ['role', 'y'], ['action', 'delete']]]);

        self::assertSame(['tags[]' => ['a', 'b'], 'role' => ['x', 'y'], 'action' => 'delete'], $event->form);
    }

    public function testManyFieldsOfOneNameTakeLinearTime(): void
    {
        $start = hrtime(true);
        $event = Event::fromArray(['type' => 'submit', 'form' => array_fill(0, 100_000, ['tags[]', 'a'])]);
        $elapsed = (hrtime(true) - $start) / 1e9;

        self::assertCount(100_000, $event->form['tags[]']);
        self::assertLessThan(1.0, $elapsed, 'a copy of the list for each value takes seconds');
    }

    public function testFilesAreTheFilesOfTheEvent(): void
    {
        $event = Event::fromArray(['type' => 'click'], files: ['photo' => 'x']);

        self::assertSame([], $event->form);
        self::assertSame(['photo' => 'x'], $event->files);
    }

    public function testClearFilesTellsThatTheHandlerTookTheFiles(): void
    {
        $event = Event::fromArray(['type' => 'submit'], files: ['photo' => 'x']);
        self::assertFalse($event->clearsFiles());

        $event->clearFiles();
        self::assertTrue($event->clearsFiles());
    }
}
