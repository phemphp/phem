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

use Phem\Handler\MemberHandler;
use PHPUnit\Framework\TestCase;

final class MemberHandlerTest extends TestCase
{
    public function testAMethodIsAClosureOnItsObject(): void
    {
        $tabs = new class {
            public string $active = 'general';

            public function select(string $name): void
            {
                $this->active = $name;
            }
        };

        $handler = MemberHandler::of($tabs, 'select');

        self::assertInstanceOf(\Closure::class, $handler);
        self::assertSame($tabs, new \ReflectionFunction($handler)->getClosureThis(), 'the object is the owner of the handler');
        $handler('security');
        self::assertSame('security', $tabs->active);
    }

    public function testAKeyOrAPropertyKeepsItsValue(): void
    {
        $remove = static fn (): null => null;

        self::assertSame($remove, MemberHandler::of(['onRemove' => $remove], 'onRemove'));
        self::assertSame($remove, MemberHandler::of((object) ['onRemove' => $remove], 'onRemove'));
        self::assertNull(MemberHandler::of(['other' => 1], 'onRemove'));
        self::assertNull(MemberHandler::of(null, 'onRemove'));
    }
}
