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

namespace App\Tests;

use Playwright\Page\PageInterface;

use function Playwright\Testing\expect;

/**
 * The `phem-sortable` controller: a drop calls the `onCall` handler of the list, in a list and
 * to another list of the group, with the pointer and with the keyboard.
 */
final class SortableTest extends E2eTestCase
{
    public function testDragMovesACardInItsColumn(): void
    {
        $page = $this->visit('/sortable');
        self::assertResponseIsSuccessful();

        $this->drag($page, '#card-a', '#card-c', 0.75);
        expect($page->locator('#order'))->toHaveExactText('b,c,a|d');
        expect($page->locator('#todo li')->first())->toHaveExactText('b');
    }

    public function testDragMovesACardToTheOtherColumn(): void
    {
        $page = $this->visit('/sortable');
        self::assertResponseIsSuccessful();

        $this->drag($page, '#card-b', '#card-d', 0.25);
        expect($page->locator('#order'))->toHaveExactText('a,c|b,d');
        expect($page->locator('#done li')->first())->toHaveExactText('b');
    }

    public function testKeyboardMovesACard(): void
    {
        $page = $this->visit('/sortable');
        self::assertResponseIsSuccessful();

        $page->locator('#card-c')->focus();
        $page->keyboard()->press('Space');
        $page->keyboard()->press('ArrowUp');
        $page->keyboard()->press('ArrowUp');
        $page->keyboard()->press('Space');

        expect($page->locator('#order'))->toHaveExactText('c,a,b|d');
        expect($page->locator('#card-c'))->toBeFocused();
    }

    // A drag with the mouse, from the center of `from` to a point of `to` (`at` is the part of
    // its height).
    private function drag(PageInterface $page, string $from, string $to, float $at): void
    {
        $a = $page->locator($from)->boundingBox();
        $b = $page->locator($to)->boundingBox();

        $mouse = $page->mouse();
        $mouse->move($a['x'] + $a['width'] / 2, $a['y'] + $a['height'] / 2);
        $mouse->down();
        $mouse->move($b['x'] + $b['width'] / 2, $b['y'] + $b['height'] * $at, ['steps' => 10]);
        $mouse->up();
    }
}
