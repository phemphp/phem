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
 * It renders one time in each render pass of the Renderer (`$pass`): a component can print
 * the slot of its parent (`<Root>{{ children }}</Root>`), and when it renders again in a later
 * pass of an event, the components of the slot render again in it.
 */
final class Slot extends LazyHtml
{
    private ?string $html = null;
    private ?int $renderedIn = null;

    /**
     * @param \Closure(): string     $render
     * @param (\Closure(): int)|null $pass   the render pass now, or null to render one time only
     */
    public function __construct(
        private readonly \Closure $render,
        private readonly ?\Closure $pass = null,
    ) {
        parent::__construct($render);
    }

    public function __toString(): string
    {
        $pass = null !== $this->pass ? ($this->pass)() : null;
        if (null === $this->html || $pass !== $this->renderedIn) {
            $this->html = ($this->render)();
            $this->renderedIn = $pass;
        }

        return $this->html;
    }
}
