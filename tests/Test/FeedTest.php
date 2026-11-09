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

namespace Phem\Tests\Test;

use Phem\Test\ComponentTester;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The feeds of Phem\Feed: lists whose items the browser keeps (tests/Fixtures/app/src/Ui/Feed/ChatPage.phem). */
final class FeedTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheFirstRenderHasTheItemsInTheFeedElement(): void
    {
        $page = $this->visit('/chat/first');

        self::assertSame(['Three', 'Four', 'Five'], self::texts($page));
        $feed = $page->document()->querySelector('[data-phem-feed]');
        self::assertMatchesRegularExpression('/\Aphem[0-9a-f]{12}-messages\z/', (string) $feed?->id);
        self::assertSame($feed?->id.'-3', $feed?->firstElementChild->id ?? null);
        self::assertMatchesRegularExpression('/\A[\w-]+\.[\w-]{22}\z/', (string) $feed?->firstElementChild?->getAttribute('data-phem-item'));
        self::assertStringNotContainsString('phem-feed:', $page->html());
    }

    public function testTheItemsAreNotInTheState(): void
    {
        $page = $this->visit('/chat/state');

        self::assertStringNotContainsString('Three', (string) $page->document()->querySelector('[data-phem-state-value]')?->getAttribute('data-phem-state-value'));
    }

    public function testAnEventAppendsAnItemAndTheBrowserKeepsTheOthers(): void
    {
        $page = $this->visit('/chat/send');
        $first = $page->document()->querySelector('[data-phem-feed] > li');

        $page->fill('input[name="draft"]', 'Six')->submit('form');

        self::assertSame(['Three', 'Four', 'Five', 'Six'], self::texts($page));
        self::assertSame($first, $page->document()->querySelector('[data-phem-feed] > li'));
        self::assertSame('Draft:', trim((string) $page->document()->querySelector('#draft')?->textContent));
    }

    public function testAnEventThatChangesOnlyTheStateKeepsTheItems(): void
    {
        $page = $this->visit('/chat/draft');

        $page->fill('input[name="draft"]', 'Hi')->click('#clear');

        self::assertSame(['Five'], self::texts($page));
    }

    public function testAHandlerInAnItemGetsTheItemAgain(): void
    {
        $page = $this->visit('/chat/delete');

        $page->click('[data-phem-feed] > li:nth-child(2) .delete');

        self::assertSame(['Three', 'Five'], self::texts($page));

        $page->click('[data-phem-feed] > li:nth-child(1) .edit');

        self::assertSame(['Three!', 'Five'], self::texts($page));
    }

    public function testMountRunsOnlyInTheFirstRender(): void
    {
        $page = $this->visit('/chat/mount');

        $page->click('[data-phem-feed] > li:nth-child(1) .edit')->click('[data-phem-feed] > li:nth-child(1) .edit');

        self::assertSame(['Three!!', 'Four', 'Five'], self::texts($page));
    }

    public function testAPrependOfManyItemsKeepsTheirOrder(): void
    {
        $page = $this->visit('/chat/older');

        $page->click('#older');

        self::assertSame(['One', 'Two', 'Three', 'Four', 'Five'], self::texts($page));

        // No older message: the button goes.
        $page->click('#older')->assertSelectorNotExists('#older');
        self::assertSame(['One', 'Two', 'Three', 'Four', 'Five'], self::texts($page));
    }

    public function testAnItemThatIsNotThereAnyMoreReloadsThePage(): void
    {
        $other = $this->visit('/chat/gone');
        $this->visit('/chat/gone')->click('[data-phem-feed] > li:nth-child(1) .delete');

        $other->click('[data-phem-feed] > li:nth-child(1) .edit')->assertReload();
    }

    public function testAChangedItemDoesNotRunTheHandler(): void
    {
        $page = $this->visit('/chat/forged');
        $items = $page->document()->querySelectorAll('[data-phem-feed] > li');
        [, $mac] = explode('.', (string) $items->item(0)?->getAttribute('data-phem-item'));
        [$other] = explode('.', (string) $items->item(1)?->getAttribute('data-phem-item'));
        $items->item(0)?->setAttribute('data-phem-item', $other.'.'.$mac);

        $page->click('[data-phem-feed] > li:nth-child(1) .delete')->assertReload();
        self::assertSame(['Three', 'Four', 'Five'], self::texts($this->visit('/chat/forged')));
    }

    public function testTheItemOfAnotherElementDoesNotRunTheHandler(): void
    {
        $page = $this->visit('/chat/swapped');
        $items = $page->document()->querySelectorAll('[data-phem-feed] > li');
        $items->item(0)?->setAttribute('data-phem-item', (string) $items->item(1)?->getAttribute('data-phem-item'));

        // The server renders the other item: the handler of the first item is not in the render.
        $page->click('[data-phem-feed] > li:nth-child(1) .delete')->assertReload();
    }

    public function testAPushRendersTheNewItemInTheOtherTabs(): void
    {
        $other = $this->visit('/chat/push');
        $this->visit('/chat/push')->fill('input[name="draft"]', 'Six')->submit('form');

        $other->receive();

        self::assertSame(['Three', 'Four', 'Five', 'Six'], self::texts($other));

        // The pushed item has its handlers.
        $other->click('[data-phem-feed] > li:nth-child(4) .edit');
        self::assertSame(['Three', 'Four', 'Five', 'Six!'], self::texts($other));
    }

    public function testAPushRemovesADeletedItemInTheOtherTabs(): void
    {
        $other = $this->visit('/chat/push-delete');
        $this->visit('/chat/push-delete')->click('[data-phem-feed] > li:nth-child(1) .delete');

        $other->receive();

        self::assertSame(['Four', 'Five'], self::texts($other));
    }

    public function testTheTabThatSentTheEventSkipsItsOwnPush(): void
    {
        $page = $this->visit('/chat/sender')->fill('input[name="draft"]', 'Six')->submit('form');

        $page->receive();

        self::assertSame(['Three', 'Four', 'Five', 'Six'], self::texts($page));
    }

    public function testAPushGoesToTheRoomOfTheItemOnly(): void
    {
        $other = $this->visit('/chat/room-b');
        $this->visit('/chat/room-a')->fill('input[name="draft"]', 'Six')->submit('form');

        $other->receive();

        self::assertSame(['Three', 'Four', 'Five'], self::texts($other));
    }

    public function testAnAsyncHandlerInAnItemGetsTheItemInTheWorker(): void
    {
        $page = $this->visit('/chat/async');

        $page->click('[data-phem-feed] > li:nth-child(1) .shout')->settle()->receive();

        self::assertSame(['THREE', 'Four', 'Five'], self::texts($page));
    }

    public function testAFeedElementThatDoesNotRenderThrows(): void
    {
        $this->expectExceptionMessage('The component "App\Ui\Feed\ChatHiddenPage" renders no element for its feed "messages".');

        $this->visit('/chat-hidden');
    }

    public function testAComponentInAnItemThrows(): void
    {
        $this->expectExceptionMessageMatches('/An item of a feed renders the component "App\\\\Ui\\\\Feed\\\\ChatText"/');

        $this->visit('/chat-bad');
    }

    /** @return list<string> */
    private static function texts(ComponentTester $page): array
    {
        $texts = [];
        foreach ($page->document()->querySelectorAll('[data-phem-feed] > li > span') as $span) {
            $texts[] = (string) $span->textContent;
        }

        return $texts;
    }
}
