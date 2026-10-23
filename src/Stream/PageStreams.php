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

/**
 * The streams to one topic (see Streams::to()). Each method takes the id of the target element
 * in the page, and returns the same object for the next stream.
 *
 * In the root element of a page, the browser marks the component around the target: the next
 * event renders it again, so the state of the page knows the change (see the `phem` Stimulus
 * controller). Before an `append` or a `prepend`, the browser removes an element of the target
 * with the id of a new element, so the user who made the change does not see it two times.
 */
final class PageStreams
{
    /** @internal use Streams::to() or Streams::everywhere() */
    public function __construct(
        private readonly Streams $streams,
        public readonly string $topic,
    ) {
    }

    /** Adds the markup at the end of the target. */
    public function append(string $target, Node $markup): self
    {
        return $this->add('append', $target, $markup);
    }

    /** Adds the markup at the start of the target. */
    public function prepend(string $target, Node $markup): self
    {
        return $this->add('prepend', $target, $markup);
    }

    /** Adds the markup before the target. */
    public function before(string $target, Node $markup): self
    {
        return $this->add('before', $target, $markup);
    }

    /** Adds the markup after the target. */
    public function after(string $target, Node $markup): self
    {
        return $this->add('after', $target, $markup);
    }

    /**
     * Replaces the target with the markup. With `$morph` (the default), the browser morphs the
     * target, so the focus, a field that the user changes, and the client attributes stay.
     */
    public function replace(string $target, Node $markup, bool $morph = true): self
    {
        return $this->add('replace', $target, $markup, $morph);
    }

    /** Replaces the content of the target with the markup, with a morph by default (see replace()). */
    public function update(string $target, Node $markup, bool $morph = true): self
    {
        return $this->add('update', $target, $markup, $morph);
    }

    /** Removes the target. */
    public function remove(string $target): self
    {
        $this->streams->add($this->topic, new Stream('remove', $target));

        return $this;
    }

    /**
     * Renders the component around the target again in each browser, with a normal event, with
     * its subtree: its own state and its access rules. As after Event::refresh(), a component
     * with no #[State] reads its data again in mount(), and a #[State] keeps the value of the
     * browser. The browser sends one event for all the refreshes that come together, and none for
     * a target that the page does not show.
     */
    public function refresh(string $target): self
    {
        $this->streams->add($this->topic, new Stream(Stream::REFRESH, $target));

        return $this;
    }

    private function add(string $action, string $target, Node $markup, bool $morph = false): self
    {
        $this->streams->add($this->topic, new Stream($action, $target, $this->streams->render($markup), $morph));

        return $this;
    }
}
