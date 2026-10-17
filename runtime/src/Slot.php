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
 * Children (or a named slot) passed to a component. It renders on first use, with the
 * context of the caller, like `props.children` in React.
 */
final class Slot extends Html
{
    private ?string $rendered = null;

    public function __construct(private readonly \Closure $render)
    {
        parent::__construct('');
    }

    public function __toString(): string
    {
        return $this->rendered ??= (string) ($this->render)();
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
