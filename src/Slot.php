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
 * Children (or a named slot) passed to a component. It renders on first use, with the
 * context of the caller, like `props.children` in React.
 *
 * It renders one time in each render pass of the Renderer (see LazyHtml): a component can print
 * the slot of its parent (`<Root>{{ children }}</Root>`), and when it renders again in a later
 * pass of an event, the components of the slot render again in it.
 */
final class Slot extends LazyHtml
{
}
