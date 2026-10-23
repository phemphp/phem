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

namespace Phem;

/**
 * Markup, like a React node: the return type of a component.
 *
 *     #[Component]
 *     function MyButton(): Node { return <button>Click</button>; }
 *
 *     public function render(): Node { ... }
 *
 * A markup literal is an Element (not rendered yet). Html is markup that rendered: a prop or a
 * slot, for example `$this->children`. Use `?Node` for a component that can render nothing.
 */
interface Node
{
}
