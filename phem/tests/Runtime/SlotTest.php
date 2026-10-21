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

use Phem\Slot;
use PHPUnit\Framework\TestCase;

final class SlotTest extends TestCase
{
    public function testASlotRendersOneTimeInEachRenderPass(): void
    {
        $renders = 0;
        $pass = 1;
        $slot = new Slot(static function () use (&$renders): string {
            return 'render '.++$renders;
        }, static function () use (&$pass): int {
            return $pass;
        });

        self::assertSame('render 1', (string) $slot);
        self::assertSame('render 1', (string) $slot, 'the same pass');
        $pass = 2;
        self::assertSame('render 2', (string) $slot, 'a component that prints it renders again in a later pass');
    }

    public function testASlotWithNoPassRendersOneTime(): void
    {
        $renders = 0;
        $slot = new Slot(static function () use (&$renders): string {
            return 'render '.++$renders;
        });

        self::assertSame('render 1', (string) $slot);
        self::assertSame('render 1', (string) $slot);
    }
}
