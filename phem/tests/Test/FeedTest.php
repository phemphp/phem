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

use Phem\DependencyInjection\TestPublisherPass;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\Topics;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Error\RuntimeError;

/** The pushes of the changes of a Phem\Feed (tests/Fixtures/app/src/pages/feed.phpm). */
final class FeedTest extends KernelTestCase
{
    use InteractsWithPhem;

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAnAppendGoesToTheOtherTabsWithTheMarkupOfTheLoopBody(): void
    {
        $other = $this->visit('/chat/lobby');
        $this->visit('/chat/lobby')
            ->fill('input[name=text]', 'Hello')
            ->click('#send')
            ->receive()
            // The tab that sent it gets the push too, and shows the item one time.
            ->assertSelectorCount(1, '.messages li');

        $other->receive()
            ->assertSelectorCount(1, '.messages li.message')
            ->assertSelectorTextContains('.messages li', 'Hello');
    }

    public function testAFeedWithNoHistoryKeepsThePushedItemsWhenThePageRendersAgain(): void
    {
        $page = $this->visit('/chat/lobby')
            ->assertSelectorExists('ul.messages[data-phem-ignore]')
            ->fill('input[name=text]', 'Stay')
            ->click('#send')
            ->receive();

        // The next event renders the page again, with no message: the element keeps its content.
        $page->click('#shout')->receive()->assertSee('STAY');
    }

    public function testTheChangesGoToTheUrlOfTheEventWithNoTopicAndNoTarget(): void
    {
        $this->visit('/chat/lobby')->fill('input[name=text]', 'Hi')->click('#send');

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);
        [$update] = $publisher->updates();
        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);
        self::assertSame([$topics->to('App\Pages\ChatRoom', ['room' => 'lobby'])], $update['topics']);
        self::assertMatchesRegularExpression('{^<turbo-stream action="append" target="(phem-f-[a-z0-9]{10})"><template>\s*<li data-phem-key="1" id="\1-1" class="message">Hi</li>\s*</template></turbo-stream>$}', $update['data']);
    }

    public function testAPushToOneUrlDoesNotGoToTheOtherUrlsOfThePage(): void
    {
        $other = $this->visit('/chat/kitchen');
        $this->visit('/chat/lobby')->fill('input[name=text]', 'Lobby only')->click('#send');

        $other->receive()->assertSelectorCount(0, '.messages li');
    }

    public function testAReplaceAndARemoveChangeTheElementOfTheItemByItsKey(): void
    {
        $other = $this->visit('/chat/lobby');
        $sender = $this->visit('/chat/lobby')->fill('input[name=text]', 'Quiet')->click('#send');
        $other->receive()->assertSee('Quiet');

        $sender->click('#shout');
        $other->receive()->assertSee('QUIET')->assertSelectorCount(1, '.messages li');

        $sender->click('#drop');
        $other->receive()->assertSelectorCount(0, '.messages li');
    }

    public function testTheChangesOfAHandlerThatThrowsAreDropped(): void
    {
        try {
            $this->visit('/chat/lobby')->click('#fail');
            self::fail('The handler did not throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('The handler failed.', $e->getMessage());
        }

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);
        self::assertSame([], $publisher->updates());
    }

    public function testAFeedWithHistoryRendersItsItemsAndPushesTheNewOnes(): void
    {
        $other = $this->visit('/history')->assertSelectorNotExists('ol.entries[data-phem-ignore]');
        // The handler changes only the form: the push gives the item to the tab that added it.
        $this->visit('/history')->fill('input[name=text]', 'First')->click('#add')
            ->receive()
            ->assertSelectorCount(1, '.entries li');
        $this->visit('/history')->assertSelectorCount(1, '.entries li');

        $other->receive()->assertSelectorTextContains('.entries li', 'First');
        // The next event renders the list from the server: the item stays, one time.
        $other->fill('input[name=text]', 'Second')->click('#add')
            ->assertSelectorCount(2, '.entries li')
            ->assertSelectorTextContains('.entries li:first-child', 'Second');
    }

    public function testARefreshRendersTheComponentOfTheFeedAgainInTheOtherTabs(): void
    {
        $other = $this->visit('/history')->assertSelectorTextContains('.count', '0 entries');
        $this->visit('/history')->fill('input[name=text]', 'First')->click('#add-count');

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);
        [$update] = $publisher->updates();
        self::assertMatchesRegularExpression('{<turbo-stream action="phem-refresh" target="phem-f-[a-z0-9]{10}"></turbo-stream>$}', $update['data']);

        $other->receive()
            ->assertSelectorTextContains('.count', '1 entries')
            ->assertSelectorCount(1, '.entries li');
    }

    public function testAChangeOnAPageWithNoSubscribeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The feed "App\Pages\QuietPage::$lines" changed, but the page "App\Pages\QuietPage" has no #[Subscribe]');

        $this->visit('/quiet')->click('#say');
    }

    public function testAPageCannotPrintTheSameFeedTwoTimes(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('Two components print the same feed in one page');

        $this->visit('/two-logs');
    }
}
