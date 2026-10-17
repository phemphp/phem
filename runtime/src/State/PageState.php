<?php

declare(strict_types=1);

namespace Mamu\State;

/**
 * The state that the browser keeps for a page: the state of each fiber, and the route values
 * of the #[Path] props. The events find the entities again from the route values.
 *
 * In the signed JSON, the `_path` key keeps the route values (a fiber id starts with `rh`).
 */
final class PageState
{
    private const PATH_KEY = '_path';

    /**
     * @param array<string, array<string, mixed>> $fibers fiber id => state
     * @param array<string, mixed>                $values the route values of the #[Path] props
     */
    public function __construct(
        public readonly array $fibers = [],
        public readonly array $values = [],
    ) {
    }

    /** @param array<string, mixed> $data the decoded JSON */
    public static function fromArray(array $data): self
    {
        $values = \is_array($data[self::PATH_KEY] ?? null) ? $data[self::PATH_KEY] : [];
        unset($data[self::PATH_KEY]);

        return new self($data, $values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ([] !== $this->values ? [self::PATH_KEY => $this->values] : []) + $this->fibers;
    }
}
