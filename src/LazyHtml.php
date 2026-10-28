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
 * Markup that renders on first use: markup that is never printed renders no component.
 *
 * It renders one time in each render pass of the Renderer (`$pass`): a component that renders
 * alone in a later pass of an event can print the same markup again (a slot of its parent, or a
 * prop), and the components of the markup render again in it. Thus they get the contexts of the
 * new pass, and they stay in the tree of the page.
 */
class LazyHtml extends Html
{
    private ?string $rendered = null;
    private ?int $renderedIn = null;

    /**
     * @param \Closure(): string     $render
     * @param (\Closure(): int)|null $pass   the render pass now, or null to render one time only
     */
    public function __construct(
        private readonly \Closure $render,
        private readonly ?\Closure $pass = null,
    ) {
        parent::__construct('');
    }

    public function __toString(): string
    {
        $pass = null !== $this->pass ? ($this->pass)() : null;

        if (null === $this->rendered || $pass !== $this->renderedIn) {
            $this->rendered = ($this->render)();
            $this->renderedIn = $pass;
        }

        return $this->rendered;
    }

    public function count(): int
    {
        return mb_strlen((string) $this);
    }

    public function jsonSerialize(): mixed
    {
        return (string) $this;
    }
}
