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

use Phem\Element;
use Phem\Html;
use Phem\HtmlList;
use Phem\Node;
use Phem\Slot;
use PHPUnit\Framework\TestCase;

final class NodeTest extends TestCase
{
    public function testAComponentCanReturnEachKindOfMarkupAsANode(): void
    {
        $render = static fn (Node $node): Node => $node;

        // A markup literal (`return <p />;`), and the markup that a component gets as a prop or a slot.
        self::assertInstanceOf(Element::class, $render(new Element('p.twig')));
        self::assertInstanceOf(Html::class, $render(new Html('<p></p>')));
        self::assertInstanceOf(Slot::class, $render(new Slot(static fn (): string => '<p></p>')));
        self::assertInstanceOf(HtmlList::class, $render(new HtmlList([new Html('<li></li>')])));
    }
}
