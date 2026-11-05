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

use Phem\Dom\Attributes;

/**
 * One Turbo Stream of a push (see PageStreams): an action on the element with the `target` id,
 * or on the elements of the `targets` CSS selector.
 *
 * `refresh` is the action of Phem (`phem-refresh`): it has no content. The browser renders the
 * component of the target again with a normal event (see the `phem` Stimulus controller). A
 * refresh with no target renders the content of the root element again.
 *
 * @internal
 */
final class Stream
{
    public const REFRESH = 'phem-refresh';

    /**
     * @param string      $action  a Turbo Stream action, or REFRESH
     * @param string|null $target  the id of the target, a CSS selector with `$many`, or null for a refresh of the content of the root element
     * @param string|null $content the HTML of the template, or null for `remove` and REFRESH
     * @param bool        $morph   `method="morph"` (for `replace` and `update`)
     * @param bool        $many    the target is a CSS selector (`targets`)
     * @param string|null $sender  the token of the event of a refresh: its browser does not refresh the fibers that the event rendered (see SenderToken)
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $target,
        public readonly ?string $content = null,
        public readonly bool $morph = false,
        public readonly bool $many = false,
        public readonly ?string $sender = null,
    ) {
    }

    /** The stream with the token of the event that sends it (see SenderToken). */
    public function withSender(string $token): self
    {
        return new self($this->action, $this->target, $this->content, $this->morph, $this->many, $token);
    }

    public function toHtml(): string
    {
        $attributes = 'action="'.Attributes::e($this->action).'"'.($this->morph ? ' method="morph"' : '');
        if (null !== $this->target) {
            $attributes .= ($this->many ? ' targets="' : ' target="').Attributes::e($this->target).'"';
        }

        if (null !== $this->sender) {
            $attributes .= ' data-phem-sender="'.Attributes::e($this->sender).'"';
        }

        return null !== $this->content
            ? '<turbo-stream '.$attributes.'><template>'.$this->content.'</template></turbo-stream>'
            : '<turbo-stream '.$attributes.'></turbo-stream>';
    }
}
