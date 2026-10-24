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
use Phem\Rendering\Renderer;
use Phem\Routing\PageName;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pushes Turbo Streams to the open pages with #[Subscribe], through the Mercure hub:
 *
 *     public function __construct(private readonly Streams $streams) {}
 *
 *     public function sign(Event $e): void
 *     {
 *         $entry = $this->entries->add($this->name, $this->message);
 *         $this->streams->to(GuestbookPage::class)
 *             ->prepend('entries', <li id="entry-{{ entry.id }}">{{ entry.message }}</li>)
 *             ->refresh('stats');
 *     }
 *
 * - to() names a page (a class name, or `Guestbook(...)` for a function page): all its URLs, or
 *   one URL with its route values. everywhere() names all the pages with #[Subscribe] (for a
 *   part of the layout).
 * - The markup renders at once, outside the page, so it shows the data of now. It is plain
 *   markup: a component in it renders with no `<phem-c>`, and an event handler or a `phem:bind`
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
    /** @var list<array{0: string, 1: Stream}> [topic, stream] */
    private array $queue = [];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StreamPublisher $publisher,
        private readonly Topics $topics = new Topics(),
        private readonly bool $private = false,
    ) {
    }

    /**
     * The streams to a page: to all its URLs, or with all its route values to one URL
     * (`to(ScientistPage::class, ['id' => 42])`). A function page is its first-class callable
     * (`to(Guestbook(...))`).
     *
     * @param class-string|string|\Closure $page
     * @param array<string, mixed>         $values
     */
    public function to(string|\Closure $page, array $values = []): PageStreams
    {
        return new PageStreams($this, $this->topics->to(PageName::of($page), $values));
    }

    /** The streams to all the pages with #[Subscribe]. */
    public function everywhere(): PageStreams
    {
        return new PageStreams($this, Topics::EVERYWHERE);
    }

    /**
     * Publishes the streams that wait, one update for each topic, in their order. Phem calls it
     * at the end of the request: call it in a long process (a command, a worker).
     */
    public function flush(): void
    {
        $updates = [];
        foreach ($this->queue as [$topic, $stream]) {
            $updates[$topic] = ($updates[$topic] ?? '').$stream->toHtml();
        }

        $this->queue = [];

        foreach ($updates as $topic => $data) {
            $this->publisher->publish([$topic], $data, $this->private);
        }
    }

    /** @internal see PageStreams */
    public function add(string $topic, Stream $stream): void
    {
        $this->queue[] = [$topic, $stream];
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
