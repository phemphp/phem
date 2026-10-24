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

namespace Phem\State;

/**
 * The state that the browser keeps for a page: the state of each fiber, the route values of
 * the #[Path] props, the shape of the last render (see TreeShape), and the components with
 * #[Subscribe] of the first render (see Phem\Stream\Subscription). The events find the entities
 * again from the route values.
 *
 * In the signed JSON, the `_path` key keeps the route values, the `_tree` key keeps the shape and
 * the `_subscribed` key keeps the components (a fiber id starts with `phem`).
 */
final class PageState
{
    private const PATH_KEY = '_path';
    private const TREE_KEY = '_tree';
    private const SUBSCRIBED_KEY = '_subscribed';

    /**
     * @param array<string, array<string, mixed>> $fibers fiber id => state
     * @param array<string, mixed>                $values     the route values of the #[Path] props
     * @param list<string>                        $subscribed the components with #[Subscribe] of the first render
     */
    public function __construct(
        public readonly array $fibers = [],
        public readonly array $values = [],
        public readonly TreeShape $shape = new TreeShape(),
        public readonly array $subscribed = [],
    ) {
    }

    /** @param array<string, mixed> $data the decoded JSON */
    public static function fromArray(array $data): self
    {
        $values = \is_array($data[self::PATH_KEY] ?? null) ? $data[self::PATH_KEY] : [];
        $shape = TreeShape::decode($data[self::TREE_KEY] ?? null);
        $subscribed = \is_array($data[self::SUBSCRIBED_KEY] ?? null) ? array_values(array_filter($data[self::SUBSCRIBED_KEY], \is_string(...))) : [];
        unset($data[self::PATH_KEY], $data[self::TREE_KEY], $data[self::SUBSCRIBED_KEY]);

        return new self($data, $values, $shape, $subscribed);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ([] !== $this->values ? [self::PATH_KEY => $this->values] : [])
            + ([] !== $this->shape->fibers ? [self::TREE_KEY => $this->shape->encode()] : [])
            + ([] !== $this->subscribed ? [self::SUBSCRIBED_KEY => $this->subscribed] : [])
            + $this->fibers;
    }
}
