<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu;

/**
 * Markup that renders on first use: markup that is never printed renders no component.
 */
class LazyHtml extends Html
{
    private ?string $rendered = null;

    /** @param \Closure(): string $render */
    public function __construct(private readonly \Closure $render)
    {
        parent::__construct('');
    }

    public function __toString(): string
    {
        return $this->rendered ??= ($this->render)();
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
