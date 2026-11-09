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

namespace Phem\Test\Internal;

/**
 * The answer to an event: the components to morph, the changes of the feeds and the browser
 * events, a redirect, or a reload (409).
 *
 * @internal
 */
final class EventResult
{
    /**
     * @param array<string, string>                    $updates    fiber id => the new HTML of its element (see Phem\Rendering\Fiber::wrapped())
     * @param list<array{name: string, detail: mixed}> $dispatched the browser events of the handler (see Phem\BrowserEventDispatcher)
     * @param string|null                              $sender     the token of the refreshes of the event (see Phem\Stream\SenderToken)
     * @param bool                                     $clearFiles whether the handler took the files of the form (see Phem\Event::clearFiles())
     * @param list<string>                             $rendered   the ids of the fibers that the event rendered, with a sender
     * @param string                                   $streams    the Turbo Streams of the feeds, after the morphs (see Phem\Http\FeedStreams)
     */
    private function __construct(
        public readonly array $updates = [],
        public readonly array $dispatched = [],
        public readonly ?string $sender = null,
        public readonly ?string $redirect = null,
        public readonly bool $reload = false,
        public readonly int $status = 200,
        public readonly bool $clearFiles = false,
        public readonly array $rendered = [],
        public readonly string $streams = '',
    ) {
    }

    /**
     * @param array<string, string>                    $updates
     * @param list<array{name: string, detail: mixed}> $dispatched
     * @param list<string>                             $rendered
     */
    public static function updates(array $updates, array $dispatched = [], ?string $sender = null, bool $clearFiles = false, array $rendered = [], string $streams = ''): self
    {
        return new self($updates, $dispatched, $sender, clearFiles: $clearFiles, rendered: $rendered, streams: $streams);
    }

    public static function redirect(string $url): self
    {
        return new self(redirect: $url);
    }

    public static function reload(): self
    {
        return new self(reload: true, status: 409);
    }

    /** An error response that the test can assert (for example 403). */
    public static function error(int $status): self
    {
        return new self(status: $status);
    }
}
