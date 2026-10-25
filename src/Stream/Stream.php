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
     * @param string|null $skip    "<token> <fiber id>": the browser of the event with this token does not refresh this fiber, it rendered it already (see SenderToken)
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $target,
        public readonly ?string $content = null,
        public readonly bool $morph = false,
        public readonly bool $many = false,
        public readonly ?string $skip = null,
    ) {
    }

    public function toHtml(): string
    {
        $attributes = 'action="'.Attributes::e($this->action).'"'.($this->morph ? ' method="morph"' : '');
        if (null !== $this->target) {
            $attributes .= ($this->many ? ' targets="' : ' target="').Attributes::e($this->target).'"';
        }

        if (null !== $this->skip) {
            $attributes .= ' data-phem-skip="'.Attributes::e($this->skip).'"';
        }

        return null !== $this->content
            ? '<turbo-stream '.$attributes.'><template>'.$this->content.'</template></turbo-stream>'
            : '<turbo-stream '.$attributes.'></turbo-stream>';
    }
}
