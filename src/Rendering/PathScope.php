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

namespace Phem\Rendering;

use Phem\State\TreeShape;

/**
 * The first pass of an event: only the fibers on the paths from the page to the targets render.
 * They give the handler and the bound properties. The other fibers are kept.
 *
 * The target of the handler is the fiber that registered it. The target of a bound value is
 * the component of its property, with its full subtree: the field can render in a child of the
 * component, or in the slot of another component.
 *
 * @internal
 */
final class PathScope implements RenderScope
{
    /**
     * @param array<string, true> $ids      the fibers on the paths
     * @param array<string, true> $subtrees the fibers whose subtrees render
     */
    private function __construct(
        private readonly array $ids,
        private readonly array $subtrees,
    ) {
    }

    /**
     * The scope of the paths to the targets in the last render, or null when the shape does not
     * know a target (then all the fibers render).
     *
     * @param list<string> $targets  fiber ids
     * @param list<string> $subtrees fiber ids: the targets whose full subtrees render
     */
    public static function to(TreeShape $shape, array $targets, array $subtrees = []): ?self
    {
        $ids = [];
        foreach ([...$targets, ...$subtrees] as $target) {
            $path = $shape->pathTo($target);
            if ([] === $path) {
                return null;
            }

            $ids += array_fill_keys($path, true);
        }

        return [] !== $ids ? new self($ids, array_fill_keys($subtrees, true)) : null;
    }

    /**
     * The fiber id of a handler id or of a bound key: the part before the first dot.
     */
    public static function fiberOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    public function renders(Fiber $fiber, ?FiberInputs $previous): bool
    {
        if (isset($this->ids[$fiber->id])) {
            return true;
        }

        for ($p = $fiber->parent; null !== $p; $p = $p->parent) {
            if (isset($this->subtrees[$p->id])) {
                return true;
            }
        }

        return false;
    }
}
