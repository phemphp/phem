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
use Phem\Html;
use Phem\Rendering\Renderer;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\Topics;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Error\RuntimeError;

/** The pushes of Phem\Stream\Streams to the pages with #[Subscribe] (tests/Fixtures/app/src/pages/push.phpm). */
final class StreamTest extends KernelTestCase
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

    public function testAPageWithSubscribeConnectsToTheHubForItsTopics(): void
    {
        $page = $this->visit('/guestbook/lobby');

        $source = $page->document()->querySelector('[data-phem-stream-value]')?->getAttribute('data-phem-stream-value');
        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);

        self::assertSame('/.well-known/mercure?match='.implode('&match=', array_map(rawurlencode(...), $topics->ofPage('App\Pages\GuestbookPage', ['room' => 'lobby']))), $source);
        // An HMAC of the names: no class name and no route value.
        self::assertStringNotContainsString('Guestbook', (string) $source);
        self::assertStringNotContainsString('lobby', (string) $source);
    }

    public function testAPageWithNoSubscribeDoesNotConnect(): void
    {
        $this->visit('/notify')->assertSelectorNotExists('[data-phem-stream-value]');
    }

    public function testAPushAddsTheMarkupAndRefreshesAComponentInTheOtherTabs(): void
    {
        $other = $this->visit('/guestbook/lobby')
            ->click('#toggle')
            ->assertSee('0 entries, open');

        // The user who signs gets the push too: the handler changes only the form.
        $this->visit('/guestbook/lobby')
            ->fill('input[name=text]', 'Hello')
            ->click('#sign')
            ->assertDontSee('Hello')
            ->receive()
            ->assertSee('Hello');

        // The prepend adds the entry, and the refresh renders the stats again, with their state.
        $other->assertDontSee('Hello')
            ->receive()
            ->assertSelectorTextContains('#entry-1', 'Hello')
            ->assertSee('1 entries, open')
            // The next event keeps the new data and the state.
            ->click('#toggle')
            ->assertSee('1 entries')
            ->assertDontSee('open');
    }

    public function testTheNextEventRendersTheComponentThatAPushChanged(): void
    {
        $other = $this->visit('/guestbook/lobby');
        $this->visit('/guestbook/lobby')->click('#note');
        $other->receive()->assertSee('A note');

        // The list is stale: the next event renders it again, with the entries of the server.
        $other->click('#toggle')
            ->assertDontSee('A note')
            ->assertSee('0 entries, open');
    }

    public function testAStaleComponentThatIsNotInThePageMakesItsParentStale(): void
    {
        $signer = $this->visit('/guestbook/lobby');
        $signer->fill('input[name=text]', 'Hi')->click('#sign');
        $other = $this->visit('/guestbook/lobby')->assertSelectorCount(1, '#entries phem-c');
        $signer->click('#forget');

        // The push removes the <li> in the <phem-c> of the entry: the entry is stale. The server
        // does not render it any more, so the list renders again, with no <phem-c> of the entry.
        $other->receive()
            ->assertDontSee('Hi')
            ->assertSelectorCount(1, '#entries phem-c')
            ->click('#toggle')
            ->assertSelectorCount(0, '#entries phem-c');
    }

    public function testAStaleRootKeepsTheStateOfItsComponents(): void
    {
        $other = $this->visit('/guestbook/lobby');
        $this->visit('/guestbook/lobby')->click('#welcome');
        $other->receive()->assertSee('Welcome back');

        // The event renders the <Root> again (stale), with its components and their state.
        $other->click('#toggle')
            ->assertSee('0 entries, open')
            ->assertSee('Welcome')
            ->assertDontSee('Welcome back')
            ->click('#toggle')
            ->assertSee('0 entries')
            ->assertDontSee('open');
    }

    public function testThePushOfTheUserWhoMadeTheChangeDoesNotShowTheItemTwoTimes(): void
    {
        $page = $this->visit('/guestbook/lobby')
            ->fill('input[name=text]', 'Once')
            ->click('#sign')
            ->receive();

        $page->assertSelectorCount(1, '#entry-1');
    }

    public function testAPushToOneUrlDoesNotGoToTheOtherUrlsOfThePage(): void
    {
        $other = $this->visit('/guestbook/kitchen');
        $this->visit('/guestbook/lobby')->fill('input[name=text]', 'Lobby only')->click('#sign');

        $other->receive()->assertDontSee('Lobby only')->assertSee('0 entries');
    }

    public function testTheStreamsOfAHandlerThatThrowsAreDropped(): void
    {
        $page = $this->visit('/guestbook/lobby');

        try {
            $page->click('#fail');
            self::fail('The handler did not throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('The handler failed.', $e->getMessage());
        }

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);
        self::assertSame([], $publisher->updates());
    }

    public function testPushedMarkupCannotHaveAHandler(): void
    {
        // Twig gives the line of the markup in the .phpm file.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('A pushed stream is plain markup, but it has an event handler');

        $this->visit('/guestbook/lobby')->click('#interactive');
    }

    public function testPushedMarkupCannotHaveTheHandlersOfMarkupThatRenderedInThePage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A pushed stream is plain markup, but it has a component or a handler of this page');

        $renderer = static::getContainer()->get('phem.renderer');
        \assert($renderer instanceof Renderer);
        $renderer->detached(new Html('<button data-phem-on-click="phem0123456789ab.f1">Save</button>'));
    }

    public function testAPushInARenderDoesNotChangeTheRenderPass(): void
    {
        $renderer = static::getContainer()->get('phem.renderer');
        \assert($renderer instanceof Renderer);
        $pass = $renderer->pass();

        self::assertSame('<b>Hi</b>', $renderer->detached(new Html('<b>Hi</b>')));
        self::assertSame($pass, $renderer->pass());
    }

    public function testAPageWithNoSubscribeGetsNoStreams(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The streams go to the pages with #[Subscribe], but "App\Pages\NotifyPage" has no #[Phem\Attribute\Subscribe] attribute.');

        $this->visit('/guestbook/lobby')->click('#elsewhere');
    }

    public function testTheUpdatesArePublicWithNoSubscriberKey(): void
    {
        $this->visit('/guestbook/lobby')->fill('input[name=text]', 'Hi')->click('#sign');

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);
        [$update] = $publisher->updates();
        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);

        self::assertSame([$topics->to('App\Pages\GuestbookPage', ['room' => 'lobby'])], $update['topics']);
        self::assertFalse($update['private']);
        self::assertSame('<turbo-stream action="prepend" target="entries"><template><li id="entry-1">Hi</li></template></turbo-stream><turbo-stream action="phem-refresh" target="stats"></turbo-stream>', $update['data']);
    }

    public function testReceiveNeedsAPageWithSubscribe(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The page has no #[Subscribe]: it gets no pushes.');

        $this->visit('/notify')->receive();
    }
}
