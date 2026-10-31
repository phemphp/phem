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

use Phem\Attribute\Broadcast;
use Phem\DependencyInjection\TestPublisherPass;
use Phem\Event;
use Phem\Handler\BroadcastMiddleware;
use Phem\Html;
use Phem\Rendering\Renderer;
use Phem\Stream\BroadcastKeys;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\Streams;
use Phem\Stream\Topics;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\Guestbook;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Error\RuntimeError;

/** The pushes of Phem\Stream\Streams to the pages with #[Broadcast] (tests/Fixtures/app/src/page/push.phpm). */
final class StreamTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAPageWithBroadcastConnectsToTheHubForItsTopics(): void
    {
        $page = $this->visit('/guestbook/lobby');

        $source = $page->document()->querySelector('[data-phem-stream-value]')?->getAttribute('data-phem-stream-value');
        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);

        self::assertSame('/.well-known/mercure?match='.implode('&match=', array_map(rawurlencode(...), $topics->ofPage('App\Page\GuestbookPage', ['room' => 'lobby']))), $source);
        // An HMAC of the names: no class name and no route value.
        self::assertStringNotContainsString('Guestbook', (string) $source);
        self::assertStringNotContainsString('lobby', (string) $source);
    }

    public function testAFunctionPageWithBroadcastGetsThePushes(): void
    {
        $other = $this->visit('/board/lobby')->assertSee('0 on the board');
        $this->visit('/guestbook/lobby')->click('#board');

        $other->receive()->assertSee('1 on the board');
    }

    public function testAComponentWithBroadcastGetsThePushesOnEachPageThatRendersIt(): void
    {
        $other = $this->visit('/inbox/lobby')->assertSee('0 in the inbox');
        $this->visit('/guestbook/lobby')->click('#inbox');

        $other->receive()->assertSee('1 in the inbox');
    }

    public function testAPageConnectsForItsComponentsWithBroadcast(): void
    {
        $page = $this->visit('/inbox/lobby');

        $source = $page->document()->querySelector('[data-phem-stream-value]')?->getAttribute('data-phem-stream-value');
        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);

        // Only the topic of the component: the page has no #[Broadcast].
        self::assertSame('/.well-known/mercure?match='.rawurlencode($topics->toComponent('App\Page\GuestbookInbox')), $source);
    }

    public function testAnEventKeepsTheComponentsWithBroadcast(): void
    {
        $other = $this->visit('/inbox/lobby')->click('#open')->assertSee('0 in the inbox, open');
        $this->visit('/guestbook/lobby')->click('#inbox');

        $other->receive()->assertSee('1 in the inbox, open');
    }

    public function testAPageWithNoBroadcastDoesNotConnect(): void
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

    public function testAPushThatRemovesTheElementOfAComponentMakesItsParentStale(): void
    {
        $signer = $this->visit('/guestbook/lobby');
        $signer->fill('input[name=text]', 'Hi')->click('#sign');
        $other = $this->visit('/guestbook/lobby')->assertSelectorCount(1, '#entries [data-phem-c]');
        $signer->click('#forget');

        // The push removes the <li> of the entry, which has the fiber id of the entry: the list
        // is stale, and it renders again with the next event.
        $other->receive()
            ->assertDontSee('Hi')
            ->assertSelectorCount(0, '#entries [data-phem-c]')
            ->click('#toggle')
            ->assertSelectorCount(0, '#entries [data-phem-c]')
            ->assertSee('0 entries');
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

    public function testAPageWithNoBroadcastGetsNoStreams(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The streams go to the pages with #[Broadcast], but "App\Page\NotifyPage" has no #[Phem\Attribute\Broadcast] attribute.');

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

        self::assertSame([$topics->toPage('App\Page\GuestbookPage', ['room' => 'lobby'])], $update['topics']);
        self::assertFalse($update['private']);
        self::assertSame('<turbo-stream action="prepend" target="entries"><template><li id="entry-1">Hi</li></template></turbo-stream><turbo-stream action="phem-refresh" target="stats"></turbo-stream>', $update['data']);
    }

    public function testAComponentWithBroadcastHasItsKeysInItsElement(): void
    {
        $page = $this->visit('/likes');
        $keys = static::getContainer()->get('phem.broadcast_keys');
        \assert($keys instanceof BroadcastKeys);

        $component = $page->document()->getElementById('likes-lobby')?->closest('[data-phem-c]');
        $all = $keys->ofComponent('App\Page\GuestbookLikes');

        self::assertSame($all.' '.$keys->ofInstance('App\Page\GuestbookLikes', ['room' => 'lobby']), $component?->getAttribute(BroadcastKeys::ATTRIBUTE));
        // An HMAC of the names: no class name and no value.
        self::assertStringNotContainsString('lobby', $all);
    }

    public function testAHandlerWithBroadcastRefreshesTheInstancesOfItsKeyInTheOtherTabs(): void
    {
        // The handler with no #[Broadcast] sends no update.
        $other = $this->visit('/likes')->click('#open-hall')->assertSee('0 likes in hall, open');
        $this->visit('/likes')->click('#like-lobby')->assertSee('1 likes in lobby');

        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);
        $keys = static::getContainer()->get('phem.broadcast_keys');
        \assert($keys instanceof BroadcastKeys);
        [$update] = $this->updates();

        self::assertSame([$topics->toComponent('App\Page\GuestbookLikes')], $update['topics']);
        self::assertMatchesRegularExpression('#^<turbo-stream action="phem-refresh" targets="\[data-phem-broadcast~=&quot;'.preg_quote($keys->ofInstance('App\Page\GuestbookLikes', ['room' => 'lobby']), '#').'&quot;\]" data-phem-skip="[0-9a-f]{24} phem[0-9a-f]{12}"></turbo-stream>$#', $update['data']);

        // Only the instance of the lobby renders again: the other keeps its state.
        $other->assertSee('0 likes in lobby')
            ->receive()
            ->assertSee('1 likes in lobby')
            ->assertSee('0 likes in hall, open');
    }

    public function testAClosureWithBroadcastRefreshesTheComponent(): void
    {
        $other = $this->visit('/likes');
        $this->visit('/likes')->click('#cheer-lobby')->assertSee('1 likes in lobby');

        $other->receive()->assertSee('1 likes in lobby');
    }

    public function testAClosureWithBroadcastNeedsAComponentThatBroadcasts(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has #[Broadcast], but the component does not broadcast');

        $component = new class {
            public function handler(): \Closure
            {
                return #[Broadcast] fn () => null;
            }
        };

        $this->broadcastMiddleware()->handle($component->handler(), new Event('click'), static fn (): mixed => null);
    }

    public function testAHandlerWithNoBroadcastDoesNotRefreshWhenOtherHandlersHaveIt(): void
    {
        $this->visit('/likes')->click('#open-lobby')->assertSee('0 likes in lobby, open');

        self::assertSame([], $this->updates());
    }

    public function testEachHandlerRefreshesTheComponentWhenNoHandlerHasBroadcast(): void
    {
        $other = $this->visit('/inbox/lobby');
        $this->visit('/inbox/lobby')->click('#open');

        $topics = static::getContainer()->get('phem.topics');
        \assert($topics instanceof Topics);
        $keys = static::getContainer()->get('phem.broadcast_keys');
        \assert($keys instanceof BroadcastKeys);
        [$update] = $this->updates();

        self::assertSame([$topics->toComponent('App\Page\GuestbookInbox')], $update['topics']);
        self::assertStringContainsString('targets="[data-phem-broadcast~=&quot;'.$keys->ofComponent('App\Page\GuestbookInbox').'&quot;]"', $update['data']);

        // The other tab renders it again, with its own state.
        $other->receive()->assertSee('0 in the inbox')->assertDontSee('open');
    }

    public function testTheTabThatSentTheEventSkipsTheComponentOfTheHandler(): void
    {
        $page = $this->visit('/likes')->click('#like-lobby')->assertSee('1 likes in lobby');
        $fiber = $page->document()->getElementById('likes-lobby')?->closest('[data-phem-c]')?->getAttribute('data-phem-c');
        [$update] = $this->updates();

        self::assertNotNull($fiber);
        self::assertMatchesRegularExpression('/data-phem-skip="[0-9a-f]{24} '.$fiber.'"/', $update['data']);

        // The tab that sent the event does not render the component again: it does not see a
        // change after the event. Another tab does.
        $guestbook = static::getContainer()->get(Guestbook::class);
        \assert($guestbook instanceof Guestbook);
        $guestbook->add('lobby', 'Like');

        $page->receive()->assertSee('1 likes in lobby');
    }

    public function testAWorkerRefreshesTheComponentAfterTheWorkOfAnAsyncHandler(): void
    {
        $other = $this->visit('/likes');
        $page = $this->visit('/likes')->click('#later-lobby');

        // The request queues no refresh: the work did not run.
        self::assertSame([], $this->updates());

        $page->settle();
        [$update] = $this->updates();

        self::assertStringNotContainsString('data-phem-skip', $update['data']);
        $other->receive()->assertSee('1 likes in lobby');
    }

    public function testAnotherActionKeepsAnEmptyTarget(): void
    {
        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);
        $streams->toPage('App\Page\GuestbookPage')->remove('');
        $streams->flush();
        [$update] = $this->updates();

        // Turbo finds no element, as before: only a refresh with no target is the page.
        self::assertSame('<turbo-stream action="remove" target=""></turbo-stream>', $update['data']);
    }

    public function testARefreshHasNoEmptyTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The target of a refresh is the id of an element');

        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);
        $streams->toPage('App\Page\GuestbookPage')->refresh('');
    }

    public function testTheStreamsRefreshTheInstancesOfAKey(): void
    {
        $other = $this->visit('/likes')->click('#open-lobby')->click('#open-hall');
        $guestbook = static::getContainer()->get(Guestbook::class);
        \assert($guestbook instanceof Guestbook);
        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);

        $guestbook->add('lobby', 'Like');
        $guestbook->add('hall', 'Like');
        $streams->toComponent('App\Page\GuestbookLikes', ['room' => 'hall'])->refresh();
        $streams->flush();

        $other->receive()
            ->assertSee('0 likes in lobby, open')
            ->assertSee('1 likes in hall, open');
    }

    public function testTheStreamsRefreshAllTheInstancesOfAComponent(): void
    {
        $other = $this->visit('/likes');
        $guestbook = static::getContainer()->get(Guestbook::class);
        \assert($guestbook instanceof Guestbook);
        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);

        $guestbook->add('lobby', 'Like');
        $guestbook->add('hall', 'Like');
        $streams->toComponent('App\Page\GuestbookLikes')->refresh();
        $streams->flush();

        $other->receive()
            ->assertSee('1 likes in lobby')
            ->assertSee('1 likes in hall');
    }

    public function testTheStreamsOfAComponentRefreshItsReplacement(): void
    {
        // src/component/Tally.phpm replaces the Tally of acme/ui, with no #[Broadcast] of its own.
        $other = $this->visit('/replaced/tally')
            ->assertSelectorTextContains('#tally-lobby', '0 in lobby')
            ->assertSelectorTextContains('#tally-hall', '0 in hall');
        $guestbook = static::getContainer()->get(Guestbook::class);
        \assert($guestbook instanceof Guestbook);
        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);

        $guestbook->add('lobby', 'Like');
        $guestbook->add('hall', 'Like');
        $streams->toComponent('Acme\Ui\Tally', ['room' => 'hall'])->refresh();
        $streams->flush();

        $other->receive()
            ->assertSelectorTextContains('#tally-lobby', '0 in lobby')
            ->assertSelectorTextContains('#tally-hall', '1 in hall');
    }

    public function testTheKeyOfARefreshHasTheKeyPropsOfTheComponent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The key of the component "App\Page\GuestbookLikes" has no prop "id": give the values of its key props ("room").');

        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);
        $streams->toComponent('App\Page\GuestbookLikes', ['id' => 1]);
    }

    public function testAComponentWithNoKeyGetsNoValuesOfAKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The component "App\Page\GuestbookInbox" has no #[BroadcastKey] prop');

        $streams = static::getContainer()->get('phem.streams');
        \assert($streams instanceof Streams);
        $streams->toComponent('App\Page\GuestbookInbox', ['room' => 'lobby']);
    }

    public function testAHandlerWithBroadcastIsAHandlerOfAComponent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('::like()" has #[Broadcast], but "class@anonymous');

        $component = new class {
            #[Broadcast]
            public function like(): void
            {
            }
        };

        $this->broadcastMiddleware()->handle($component->like(...), new Event('click'), static fn (): mixed => null);
    }

    public function testReceiveNeedsAPageWithBroadcast(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The page has no #[Broadcast]: it gets no pushes.');

        $this->visit('/notify')->receive();
    }

    /** @return list<array{topics: list<string>, data: string, private: bool}> */
    private function updates(): array
    {
        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);

        return $publisher->updates();
    }

    private function broadcastMiddleware(): BroadcastMiddleware
    {
        $middleware = static::getContainer()->get('phem.handler_middleware.broadcast');
        \assert($middleware instanceof BroadcastMiddleware);

        return $middleware;
    }
}
