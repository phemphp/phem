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
 * The scroll position through the morph (`phem:keep-scroll`, `phem:scroll-into-view`) and the
 * messages for screen readers (`$e->announce()`). The page turns off the scroll anchoring of
 * the browser.
 */
final class ScrollTest extends E2eTestCase
{
    /** The first item in view of the box, and its distance from the top of the box. */
    private const FIRST_IN_VIEW = <<<'JS'
        (id) => {
            const thread = document.getElementById(id ?? 'thread')
            const top = thread.getBoundingClientRect().top
            const li = [...thread.querySelectorAll('li')].find((el) => el.getBoundingClientRect().bottom > top)

            return [li.textContent.trim(), Math.round(li.getBoundingClientRect().top - top)]
        }
        JS;

    /** Whether the item is all in the view of the thread. */
    private const IN_VIEW = <<<'JS'
        (li) => {
            const thread = document.getElementById('thread').getBoundingClientRect()
            const item = li.getBoundingClientRect()

            return item.top >= thread.top && item.bottom <= thread.bottom
        }
        JS;

    public function testSignScrollsToTheNewEntryAndAnnouncesIt(): void
    {
        $page = $this->visit('/scroll/keep/'.self::room());
        self::assertResponseIsSuccessful();
        self::scrollThread($page, 200);
        self::assertFalse(self::inView($page, '#old-1'));

        $page->getByLabel('Message')->fill('Hello');
        $page->locator('#sign')->click();

        expect($page->locator('#thread li')->first())->toHaveExactText('Hello');
        $page->waitForFunction('() => document.getElementById("thread").scrollTop === 0');
        self::assertTrue(self::inView($page, '#thread li >> nth=0'));
        expect($page->locator('[data-phem-announcer]'))->toHaveText('Message added');
        expect($page->getByRole('status'))->toHaveText('Message added');
    }

    public function testAPushKeepsTheEntryInViewAtItsPlace(): void
    {
        $room = self::room();
        $alice = $this->visit('/scroll/keep/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/scroll/keep/'.$room);
        $this->waitForStream($bob);
        self::scrollThread($bob, 120);
        self::assertSame(['Old 3', -20], $bob->evaluate(self::FIRST_IN_VIEW));

        $alice->getByLabel('Message')->fill('Hello from Alice');
        $alice->locator('#sign')->click();

        // The entry comes before the entry in view: Bob still reads the same entry.
        expect($bob->locator('#thread li')->first())->toHaveExactText('Hello from Alice');
        self::assertSame(['Old 3', -20], $bob->evaluate(self::FIRST_IN_VIEW));
        // A push does not scroll to the entry of another browser.
        self::assertFalse(self::inView($bob, '#thread li >> nth=0'));
    }

    public function testAPushKeepsTheThreadAtItsEndWithEnd(): void
    {
        $room = self::room();
        $alice = $this->visit('/scroll/end/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/scroll/end/'.$room);
        $this->waitForStream($bob);
        self::scrollThread($bob, 10_000);
        self::assertTrue(self::inView($bob, '#old-10'));

        $alice->getByLabel('Message')->fill('Hello from Alice');
        $alice->locator('#sign')->click();

        expect($bob->locator('#thread li')->first())->toHaveExactText('Hello from Alice');
        self::assertTrue(self::atEnd($bob, 'thread'));
    }

    public function testAPushToAFeedKeepsTheChatAtItsEnd(): void
    {
        $room = self::room();
        $alice = $this->visit('/scroll-chat/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/scroll-chat/'.$room);
        $this->waitForStream($bob);
        self::scrollBox($bob, 'messages', 10_000);
        self::assertTrue(self::atEnd($bob, 'messages'));

        $alice->getByLabel('Message')->fill('Hello from Alice');
        $alice->locator('#send')->click();

        // The feed item of the push comes at the end: Bob's box stays at its end, and shows it.
        expect($bob->locator('#messages li')->last())->toHaveExactText('Hello from Alice');
        self::assertTrue(self::atEnd($bob, 'messages'));
    }

    public function testOlderItemsOfAFeedKeepTheMessageInViewAtItsPlace(): void
    {
        $page = $this->visit('/scroll-chat/'.self::room());
        self::assertResponseIsSuccessful();
        expect($page->locator('#messages li'))->toHaveCount(4);
        self::scrollBox($page, 'messages', 70);
        self::assertSame(['Three', -20], $page->evaluate(self::FIRST_IN_VIEW, 'messages'));

        // The event prepends the older message to the feed, before the message in view.
        $page->locator('#older')->click();

        expect($page->locator('#messages li')->first())->toHaveExactText('One');
        self::assertSame(['Three', -20], $page->evaluate(self::FIRST_IN_VIEW, 'messages'));
    }

    public function testTheLiveRegionHasNoOldMessageAfterTheBackButton(): void
    {
        $page = $this->visit('/scroll/keep/'.self::room());
        self::assertResponseIsSuccessful();
        $page->getByLabel('Message')->fill('Hello');
        $page->locator('#sign')->click();
        expect($page->getByRole('status'))->toHaveText('Message added');

        // A Turbo visit, then the snapshot of the page from the cache of Turbo.
        $page->locator('#away')->click();
        $page->waitForURL('**/counter');
        $page->goBack();
        $page->waitForURL('**/scroll/keep/*');

        expect($page->locator('[data-phem-announcer]'))->toHaveCount(1);
        expect($page->getByRole('status'))->toHaveText('');

        $page->getByLabel('Message')->fill('Again');
        $page->locator('#sign')->click();

        expect($page->locator('#thread li')->first())->toHaveExactText('Again');
        expect($page->getByRole('status'))->toHaveText('Message added');
        expect($page->locator('[data-phem-announcer]'))->toHaveCount(1);
    }

    private static function inView(PageInterface $page, string $selector): bool
    {
        return (bool) $page->locator($selector)->evaluate(self::IN_VIEW);
    }

    private static function scrollThread(PageInterface $page, int $top): void
    {
        self::scrollBox($page, 'thread', $top);
    }

    private static function scrollBox(PageInterface $page, string $id, int $top): void
    {
        $page->evaluate(\sprintf('() => { document.getElementById("%s").scrollTop = %d }', $id, $top));
    }

    private static function atEnd(PageInterface $page, string $id): bool
    {
        return (bool) $page->evaluate(\sprintf('() => { const box = document.getElementById("%s"); return box.scrollHeight - box.scrollTop - box.clientHeight <= 2 }', $id));
    }

    private static function room(): string
    {
        return 'room-'.bin2hex(random_bytes(4));
    }
}
