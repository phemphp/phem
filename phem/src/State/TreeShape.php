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

use Phem\Rendering\Tree;

/**
 * The shape of the last render of a page: for each fiber, the id of its parent and the hash of
 * its own HTML (the HTML without the content of its child fibers, see Fiber::$ownHtml).
 *
 * The browser keeps it in the signed state. With it, an event knows the render from before the
 * handler with no second render, and it knows the path from the page to a fiber.
 *
 * A fiber around the root element (see Fiber::$holdsRoot) has no hash: it is not in the
 * browser, and its HTML has the random token of the root element.
 */
final class TreeShape
{
    /** @var array<string, list<string>>|null parent id => child ids */
    private ?array $children = null;

    /**
     * @param array<string, array{0: string|null, 1: string|null}> $fibers fiber id => [parent id, hash]
     */
    public function __construct(public readonly array $fibers = [])
    {
    }

    /**
     * The shape of the page after the last pass of a tree. A fiber that did not render in that
     * pass keeps the hash of the shape before (see Tree::page()).
     */
    public static function of(Tree $tree): self
    {
        $fibers = [];
        foreach ($tree->page() as $id => [$parent, $fiber]) {
            $fibers[$id] = [$parent, null !== $fiber ? ($fiber->holdsRoot ? null : self::hash($fiber->ownHtml[$fiber->pass] ?? '')) : $tree->shape->hashOf($id)];
        }

        return new self($fibers);
    }

    /**
     * The shape in the decoded JSON. A value with another form is ignored.
     *
     * @param mixed $data the value of the `_tree` key
     */
    public static function fromArray(mixed $data): self
    {
        $fibers = [];
        foreach (\is_array($data) ? $data : [] as $id => $entry) {
            if (\is_string($id) && \is_array($entry) && array_is_list($entry) && 2 === \count($entry)
                && (null === $entry[0] || \is_string($entry[0])) && (null === $entry[1] || \is_string($entry[1]))) {
                $fibers[$id] = [$entry[0], $entry[1]];
            }
        }

        return new self($fibers);
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    public function toArray(): array
    {
        return $this->fibers;
    }

    /** A 64-bit hash: it only tells whether the HTML changed, and the state stays small. */
    public static function hash(string $html): string
    {
        return hash('xxh3', $html);
    }

    /** The hash of the own HTML of a fiber in the last render, or null. */
    public function hashOf(string $id): ?string
    {
        return $this->fibers[$id][1] ?? null;
    }

    /** The id of the parent of a fiber in the last render, or null for the page or an unknown fiber. */
    public function parentOf(string $id): ?string
    {
        return $this->fibers[$id][0] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->fibers[$id]);
    }

    /** The fiber with no parent: the page. */
    public function root(): ?string
    {
        foreach ($this->fibers as $id => [$parent]) {
            if (null === $parent) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The children of a fiber, in their order.
     *
     * @return list<string>
     */
    public function childrenOf(string $id): array
    {
        $this->children ??= self::children($this->fibers);

        return $this->children[$id] ?? [];
    }

    /**
     * The ids from the page to a fiber, the fiber last. Empty for an unknown fiber.
     *
     * @return list<string>
     */
    public function pathTo(string $id): array
    {
        $path = [];
        for ($current = $id; null !== $current && $this->has($current) && !\in_array($current, $path, true); $current = $this->parentOf($current)) {
            $path[] = $current;
        }

        return array_reverse($path);
    }

    /**
     * @param array<string, array{0: string|null, 1: string|null}> $fibers
     *
     * @return array<string, list<string>>
     */
    private static function children(array $fibers): array
    {
        $children = [];
        foreach ($fibers as $id => [$parent]) {
            if (null !== $parent) {
                $children[$parent][] = $id;
            }
        }

        return $children;
    }
}
