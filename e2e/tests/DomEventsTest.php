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

use function Playwright\Testing\expect;

/**
 * The DOM events with a default action or with no bubble: a drop, a paste, a context menu and
 * a scroll.
 */
final class DomEventsTest extends E2eTestCase
{
    /** Dispatches a drag over the zone and a drop of two files, as the browser does. */
    private const DROP = <<<'JS'
        () => {
            const files = new DataTransfer()
            files.items.add(new File(['alpha'], 'a.png', { type: 'image/png' }))
            files.items.add(new File(['beta'], 'b.png', { type: 'image/png' }))
            const zone = document.getElementById('zone')
            const over = new DragEvent('dragover', { dataTransfer: files, bubbles: true, cancelable: true })
            zone.dispatchEvent(over)
            const drop = new DragEvent('drop', { dataTransfer: files, bubbles: true, cancelable: true })
            zone.dispatchEvent(drop)

            return [over.defaultPrevented, drop.defaultPrevented]
        }
        JS;

    /** Dispatches a paste of a file in the comment. */
    private const PASTE = <<<'JS'
        () => {
            const data = new DataTransfer()
            data.items.add(new File(['shot'], 'screenshot.png', { type: 'image/png' }))
            document.getElementById('comment').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }))
        }
        JS;

    public function testDropSendsTheFilesAndTheBrowserDoesNotOpenThem(): void
    {
        $page = $this->visit('/drop');
        self::assertResponseIsSuccessful();

        self::assertSame([true, true], $page->evaluate(self::DROP), 'the dragover and the drop are cancelled');
        expect($page->locator('#files'))->toHaveExactText('a.png alpha, b.png beta');
        expect($page->locator('#last'))->toHaveExactText('drop');
    }

    public function testPasteSendsTheFiles(): void
    {
        $page = $this->visit('/drop');
        self::assertResponseIsSuccessful();

        $page->evaluate(self::PASTE);
        expect($page->locator('#files'))->toHaveExactText('screenshot.png shot');
        expect($page->locator('#last'))->toHaveExactText('paste');
    }

    public function testContextMenuAndScrollSendTheirEvents(): void
    {
        $page = $this->visit('/drop');
        self::assertResponseIsSuccessful();

        $page->locator('#menu li')->click(['button' => 'right']);
        expect($page->locator('#last'))->toHaveExactText('contextmenu');

        // A scroll does not bubble: the root element gets it in the capture phase.
        $page->evaluate('() => { document.getElementById("list").scrollTop = 100 }');
        expect($page->locator('#last'))->toHaveExactText('scroll');
    }
}
