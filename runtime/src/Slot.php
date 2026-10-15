<?php

declare(strict_types=1);

namespace Rephp;

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
