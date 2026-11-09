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

namespace Phem\Stream;

use Phem\Node;
use Phem\Rendering\FeedIdentity;
use Phem\Rendering\FeedItemToken;
use Phem\Rendering\Renderer;
use Phem\Routing\PageName;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pushes Turbo Streams to the open pages with #[Broadcast], or with a component with
 * #[Broadcast], through the Mercure hub:
 *
 *     public function __construct(private readonly Streams $streams) {}
 *
 *     public function sign(Event $e): void
 *     {
 *         $entry = $this->entries->add($this->name, $this->message);
 *         $this->streams->toPage(GuestbookPage::class)
 *             ->prepend('entries', <li id="entry-{{ entry.id }}">{{ entry.message }}</li>)
 *             ->refresh('stats');
 *     }
 *
 * - toPage() names a page (a class name, or `GuestbookPage(...)` for a function page): all its URLs,
 *   or one URL with its route values. A page or a component with #[Broadcast(on: ...)] needs
 *   no stream for the changes of its entities (see EntityRefreshes). toComponent() names a
 *   component with #[Broadcast]: all the pages that render it. A class component with
 *   #[Broadcast] refreshes itself after its handlers (see Phem\Handler\BroadcastMiddleware).
 *   everywhere() names all the pages with #[Broadcast] (for a part of the layout).
 * - The markup renders at once, outside the page, so it shows the data of now. It is plain
 *   markup: a component in it renders with no fiber id, and an event handler or a `phem:bind`
 *   throws. For interactive content, use refresh(): each browser renders the component of the
 *   target again with a normal event, with its own state and its access rules, as after
 *   Event::refresh(): a component with no #[State] reads its data again in mount().
 * - The streams go to the hub at the end of the request (see flush()), after the transaction of
 *   the event: a refresh reads the new data. When an event handler throws, its streams are
 *   dropped (see Phem\Handler\StreamsMiddleware).
 * - The same markup goes to all the viewers of the page: do not push data for one user only.
 */
final class Streams implements ResetInterface
{
    /** @var list<array{0: string, 1: TurboStream}> [topic, stream] */
    private array $queue = [];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StreamPublisher $publisher,
        private readonly Topics $topics = new Topics(),
        private readonly bool $private = false,
        private readonly BroadcastKeys $keys = new BroadcastKeys(),
        private readonly SenderToken $sender = new SenderToken(),
        private readonly ?FeedItemToken $feedTokens = null,
        private readonly FeedIdentity $feedIdentity = new FeedIdentity(),
    ) {
    }

    /**
     * The streams to a page: to all its URLs, or with all its route values to one URL
     * (`toPage(ScientistPage::class, ['id' => 42])`). An entity prop takes the entity, also a
     * Doctrine reference (`toPage(OrderPage::class, ['order' => $order])`): all the routes of the
     * page give the same URL topic (see PathTopicValues). A function page is its first-class
     * callable (`toPage(GuestbookPage(...))`).
     *
     * @param class-string|string|\Closure $page
     * @param array<string, mixed>         $values
     */
    public function toPage(string|\Closure $page, array $values = []): PageStreams
    {
        return new PageStreams($this, $this->topics->toPage(PageName::of($page), $values));
    }

    /**
     * The streams to a component with #[Broadcast]: to all the pages that render it
     * (`toComponent(Inbox::class)`, or `toComponent(Inbox(...))` for a function component).
     * refresh() with no target renders its instances again: all of them, or with the values of
     * all its key props only the instances with these values
     * (`toComponent(LikeButton::class, ['post' => $post])`, see Phem\Attribute\Broadcast).
     *
     * @param class-string|string|\Closure $component
     * @param array<string, mixed>         $key       the values of the key props
     */
    public function toComponent(string|\Closure $component, array $key = []): PageStreams
    {
        $name = ltrim(PageName::of($component), '\\');

        return new PageStreams($this, $this->topics->toComponent($name), [] !== $key ? $this->keys->ofInstance($name, $key) : $this->keys->ofComponent($name), $name);
    }

    /** The streams to all the pages with #[Broadcast]. */
    public function everywhere(): PageStreams
    {
        return new PageStreams($this, Topics::EVERYWHERE);
    }

    /**
     * Publishes the streams that wait, one update for each topic, in their order, with no
     * refresh two times in a topic. Phem calls it at the end of a request, of a command, and of a
     * message of a Messenger worker (see Phem\EventListener\StreamListener): call it in another
     * long process.
     */
    public function flush(): void
    {
        $updates = [];
        $refreshes = [];
        foreach ($this->queue as [$topic, $stream]) {
            $html = $stream->toHtml();

            // The same refresh of a handler and of an entity change goes out once.
            if (TurboStream::REFRESH === $stream->action) {
                if (isset($refreshes[$topic][$html])) {
                    continue;
                }

                $refreshes[$topic][$html] = true;
            }

            $updates[$topic] = ($updates[$topic] ?? '').$html;
        }

        $this->queue = [];

        foreach ($updates as $topic => $data) {
            $this->publisher->publish([$topic], $data, $this->private);
        }
    }

    /**
     * A refresh and an item of a feed of an event request get the token of the event (see SenderToken).
     *
     * @internal see PageStreams
     */
    public function add(string $topic, TurboStream $stream): void
    {
        if (\in_array($stream->action, [TurboStream::REFRESH, TurboStream::FEED], true) && null !== $token = $this->sender->forRefresh()) {
            $stream = $stream->withSender($token);
        }

        $this->queue[] = [$topic, $stream];
    }

    /**
     * The key and the signed item of an item of a feed (see FeedPush): the token is valid only for
     * the instances with the broadcast key of the push.
     *
     * @internal see FeedPush
     *
     * @return array{0: string, 1: string}
     */
    public function feedItem(string $key, string $component, string $feed, object $item): array
    {
        $tokens = $this->feedTokens ?? throw new \LogicException('A push to a feed needs the phem.feed_item_token service.');
        [$class, $identifier] = $this->feedIdentity->of($feed, $item);

        return [$this->feedIdentity->key($feed, $item), $tokens->sign(FeedItemToken::pushScope($key), $component, $feed, $class, $identifier)];
    }

    /** @internal see PageStreams */
    public function render(Node $markup): string
    {
        return $this->renderer->detached($markup);
    }

    /**
     * The place in the queue now: rollback() drops the streams after it.
     *
     * @internal see Phem\Handler\StreamsMiddleware
     */
    public function mark(): int
    {
        return \count($this->queue);
    }

    /** @internal see Phem\Handler\StreamsMiddleware */
    public function rollback(int $mark): void
    {
        $this->queue = \array_slice($this->queue, 0, $mark);
    }

    public function reset(): void
    {
        $this->queue = [];
    }
}
