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

use Phem\Rendering\Fiber;
use Phem\Rendering\Tree;
use PHPUnit\Framework\TestCase;

/**
 * The queue of the streamed `<Suspense>` boundaries, and a fiber that renders again in the same
 * pass (see Phem\Rendering\Renderer::resume()).
 */
final class TreeTest extends TestCase
{
    public function testAFiberThatRendersAgainInThePassGetsTheSameHandlerIds(): void
    {
        $tree = new Tree();
        $tree->begin();
        $fiber = new Fiber('phem1', '/A#0', 'A');
        $child = new Fiber('phem2', '/A#0/B#0', 'B');
        $fiber->children = [$child];
        $tree->push($fiber);

        self::assertSame('phem1.load', $tree->addHandler(static fn () => null, 'load'));
        self::assertSame('phem1.load~2', $tree->addHandler(static fn () => null, 'load'));
        $tree->pop();
        $tree->push($child);
        self::assertSame('phem2.click', $tree->addHandler(static fn () => null, 'click'));
        $tree->pop();

        $tree->forget($fiber);

        self::assertNull($tree->handler('phem1.load'));
        self::assertNull($tree->handler('phem2.click'));
        $tree->push($fiber);
        self::assertSame('phem1.load', $tree->addHandler(static fn () => null, 'load'));
    }

    public function testTheQueueSkipsABoundaryThatIsNotInThePageAnyMore(): void
    {
        $tree = new Tree();
        $tree->begin();
        $outer = new Fiber('phem1', '/Suspense#0', 'Suspense');
        $inner = new Fiber('phem2', '/Suspense#0/Suspense#0', 'Suspense');
        $outer->begin($tree->pass);
        $inner->begin($tree->pass);
        $inner->parent = $outer;
        $outer->children = [$inner];

        $tree->defer($outer);
        $tree->defer($inner);
        $tree->defer($outer);
        self::assertTrue($tree->hasDeferred());

        // The outer boundary loads: its children replace its fallback, with the inner boundary.
        self::assertSame($outer, $tree->nextDeferred());
        $outer->children = [];

        self::assertNull($tree->nextDeferred());
        self::assertFalse($tree->hasDeferred());
    }
}
