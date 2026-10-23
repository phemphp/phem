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
 * One Turbo Stream of a push (see PageStreams): an action on the element with the `target` id.
 *
 * `refresh` is the action of Phem (`phem-refresh`): it has no content. The browser renders the
 * component of the target again with a normal event (see the `phem` Stimulus controller).
 *
 * @internal
 */
final class Stream
{
    public const REFRESH = 'phem-refresh';

    /**
     * @param string      $action  a Turbo Stream action, or REFRESH
     * @param string|null $content the HTML of the template, or null for `remove` and REFRESH
     * @param bool        $morph   `method="morph"` (for `replace` and `update`)
     */
    public function __construct(
        public readonly string $action,
        public readonly string $target,
        public readonly ?string $content = null,
        public readonly bool $morph = false,
    ) {
    }

    public function toHtml(): string
    {
        $attributes = 'action="'.Attributes::e($this->action).'"'.($this->morph ? ' method="morph"' : '').' target="'.Attributes::e($this->target).'"';

        return null !== $this->content
            ? '<turbo-stream '.$attributes.'><template>'.$this->content.'</template></turbo-stream>'
            : '<turbo-stream '.$attributes.'></turbo-stream>';
    }
}
