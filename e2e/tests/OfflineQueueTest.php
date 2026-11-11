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

use Playwright\Network\RouteInterface;
use Playwright\Page\PageInterface;

use function Playwright\Testing\expect;

/**
 * The offline queue (`data-phem-offline-queue-value`): the events that wait for the connection
 * stay in IndexedDB, and the next load of the page sends them.
 */
final class OfflineQueueTest extends E2eTestCase
{
    /** @var (\Closure(RouteInterface): void)|null the route that makes the events fail */
    private ?\Closure $offline = null;

    public function testTheNextLoadSendsTheEventsOfAClosedOfflinePage(): void
    {
        $page = $this->visit('/offline');
        self::assertResponseIsSuccessful();
        $this->goOffline($page);
        $page->getByLabel('Note')->fill('first');
        $page->locator('#add')->click();
        $page->getByLabel('Note')->fill('second');
        $page->locator('#add')->click();
        expect($page->locator('#notes li'))->toHaveCount(0);

        // The two events are in IndexedDB, then the tab closes before the connection comes back.
        $page->waitForFunction(<<<'JS'
            () => new Promise((resolve) => {
                const open = indexedDB.open("phem")
                open.onsuccess = () => {
                    const get = open.result.transaction("offline-queue").objectStore("offline-queue").getAll()
                    get.onsuccess = () => resolve(get.result[0]?.events.length === 2)
                    get.onerror = () => resolve(false)
                }
                open.onerror = () => resolve(false)
            })
            JS);
        $page->goto('about:blank');
        $this->goOnline($page);

        // A new load of the page starts with no notes, and sends the two events.
        $page = $this->visit('/offline');
        $this->assertNotes($page, ['first', 'second']);

        // The record left IndexedDB: one more load sends nothing.
        $page = $this->visit('/offline');
        expect($page->locator('#add'))->toHaveCount(1);
        $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 300))');
        expect($page->locator('#notes li'))->toHaveCount(0);
    }

    public function testTheOpenPageSendsItsEventsWhenTheConnectionComesBack(): void
    {
        $page = $this->visit('/offline');
        self::assertResponseIsSuccessful();
        $this->goOffline($page);
        $page->getByLabel('Note')->fill('first');
        $page->locator('#add')->click();
        $page->getByLabel('Note')->fill('second');
        $page->locator('#add')->click();
        expect($page->locator('#notes li'))->toHaveCount(0);
        $this->goOnline($page);

        // Each event has the value of its own time.
        $this->assertNotes($page, ['first', 'second']);
    }

    /**
     * The browser is offline: `navigator.onLine` is false, and the events of the page fail. The
     * kernel client answers the requests in the process, so the offline mode alone does not stop them.
     */
    private function goOffline(PageInterface $page): void
    {
        static::getPlaywrightClient()->context()?->setOffline(true);
        $this->offline = static fn (RouteInterface $route) => $route->abort('internetdisconnected');
        $page->route('**/offline', $this->offline);
    }

    // Removes only the route of goOffline(): the kernel client answers the requests with its own route.
    private function goOnline(PageInterface $page): void
    {
        $page->unroute('**/offline', $this->offline);
        static::getPlaywrightClient()->context()?->setOffline(false);
    }

    /** @param list<string> $notes */
    private function assertNotes(PageInterface $page, array $notes): void
    {
        expect($page->locator('#notes li'))->toHaveCount(\count($notes));
        foreach ($notes as $i => $note) {
            expect($page->locator('#notes li')->nth($i))->toHaveExactText($note);
        }
    }
}
