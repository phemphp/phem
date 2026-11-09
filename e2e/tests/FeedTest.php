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
 * The feeds of Phem\Feed in a real browser (src/Ui/Chat/ChatPage.phem): the browser keeps the
 * items, the morph does not remove them, and a push gives a new item to the other browsers.
 */
final class FeedTest extends E2eTestCase
{
    private const ITEMS = '[data-phem-feed] > li';

    public function testTheFirstRenderShowsTheNewestItems(): void
    {
        $page = $this->visit('/chat/'.self::room());
        self::assertResponseIsSuccessful();

        self::assertSame(['Three', 'Four', 'Five'], self::texts($page));
    }

    public function testSendAppendsAnItemAndKeepsTheElementsOfTheOthers(): void
    {
        $page = $this->visit('/chat/'.self::room());
        self::mark($page);

        $page->getByLabel('Message')->fill('Six');
        $page->locator('#send')->click();

        expect($page->locator(self::ITEMS))->toHaveCount(4);
        self::assertSame(['Three', 'Four', 'Five', 'Six'], self::texts($page));
        self::assertSame([true, true, true, false], self::marked($page));
    }

    public function testTypingInALiveFieldKeepsTheItems(): void
    {
        $page = $this->visit('/chat/'.self::room());
        self::mark($page);

        // Each key sends an event that morphs the component: its feed element has no items.
        $page->getByLabel('Message')->pressSequentially('Hello', ['delay' => 50]);

        expect($page->locator('#draft'))->toHaveText('Draft: Hello');
        self::assertSame(['Three', 'Four', 'Five'], self::texts($page));
        self::assertSame([true, true, true], self::marked($page));
    }

    public function testDeleteInAnItemRemovesThatItemOnly(): void
    {
        $page = $this->visit('/chat/'.self::room());
        self::mark($page);

        $page->locator(self::ITEMS.':nth-child(2) .delete')->click();

        expect($page->locator(self::ITEMS))->toHaveCount(2);
        self::assertSame(['Three', 'Five'], self::texts($page));
        self::assertSame([true, true], self::marked($page));
    }

    public function testEditInAnItemRendersTheItemAgain(): void
    {
        $page = $this->visit('/chat/'.self::room());

        $page->locator(self::ITEMS.':nth-child(1) .edit')->click();

        expect($page->locator(self::ITEMS.':nth-child(1) span'))->toHaveText('Three!');
        self::assertSame(['Three!', 'Four', 'Five'], self::texts($page));
    }

    public function testOlderPrependsTheItemsInTheirOrder(): void
    {
        $page = $this->visit('/chat/'.self::room());
        self::mark($page);

        $page->locator('#older')->click();

        expect($page->locator(self::ITEMS))->toHaveCount(5);
        self::assertSame(['One', 'Two', 'Three', 'Four', 'Five'], self::texts($page));
        self::assertSame([false, false, true, true, true], self::marked($page));
    }

    public function testAnItemFromAnotherBrowserHasItsHandlers(): void
    {
        $room = self::room();
        $alice = $this->visit('/chat/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/chat/'.$room);
        $this->waitForStream($bob);

        $alice->getByLabel('Message')->fill('Hello from Alice');
        $alice->locator('#send')->click();
        expect($alice->locator(self::ITEMS))->toHaveCount(4);

        // Bob's browser renders the pushed item itself: its buttons work.
        expect($bob->locator(self::ITEMS))->toHaveCount(4);
        self::assertSame(['Three', 'Four', 'Five', 'Hello from Alice'], self::texts($bob));

        $bob->locator(self::ITEMS.':nth-child(4) .delete')->click();

        expect($bob->locator(self::ITEMS))->toHaveCount(3);
        expect($alice->locator(self::ITEMS))->toHaveCount(3);
        // Alice did not render her own item two times.
        self::assertSame(['Three', 'Four', 'Five'], self::texts($alice));
    }

    public function testAChangedItemDeletesNothing(): void
    {
        $room = self::room();
        $page = $this->visit('/chat/'.$room);

        // The item of the second element with the signature of the first one.
        self::mark($page);
        $page->evaluate(<<<'JS'
            () => {
                const [first, second] = document.querySelectorAll('[data-phem-feed] > li')
                const mac = first.dataset.phemItem.split('.')[1]
                first.dataset.phemItem = second.dataset.phemItem.split('.')[0] + '.' + mac
            }
            JS);
        $page->locator(self::ITEMS.':nth-child(1) .delete')->click();

        // The server does not find the handler: the page loads again (a Turbo visit gives new
        // elements), with all the items.
        $page->waitForFunction('() => document.querySelector("[data-phem-feed] > li")?.marked === undefined');
        expect($page->locator(self::ITEMS))->toHaveCount(3);
        self::assertSame(['Three', 'Four', 'Five'], self::texts($page));
    }

    public function testANewKeyStartsANewFeed(): void
    {
        $prefix = self::room();
        $page = $this->visit('/chat-switch/'.$prefix);
        self::mark($page);

        $page->locator('#room-b')->click();

        expect($page->locator('h2'))->toHaveText('Room '.$prefix.'-b');
        expect($page->locator(self::ITEMS))->toHaveCount(3);
        self::assertSame([false, false, false], self::marked($page));
    }

    /** A new room for each test: the rooms share the database of all the tests. */
    private static function room(): string
    {
        return 'room-'.bin2hex(random_bytes(4));
    }

    /** Marks the elements of the items: a mark stays on an element that the browser keeps. */
    private static function mark(PageInterface $page): void
    {
        $page->evaluate('() => document.querySelectorAll("[data-phem-feed] > li").forEach((li) => { li.marked = true })');
    }

    /** @return list<bool> */
    private static function marked(PageInterface $page): array
    {
        return $page->evaluate('() => [...document.querySelectorAll("[data-phem-feed] > li")].map((li) => li.marked === true)');
    }

    /** @return list<string> */
    private static function texts(PageInterface $page): array
    {
        return $page->evaluate('() => [...document.querySelectorAll("[data-phem-feed] > li > span")].map((span) => span.textContent)');
    }
}
