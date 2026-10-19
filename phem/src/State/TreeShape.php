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
    /**
     * @param array<string, array{0: string|null, 1: string|null}> $fibers fiber id => [parent id, hash]
     */
    public function __construct(public readonly array $fibers = [])
    {
    }

    /** The shape of the last pass of a tree. */
    public static function of(Tree $tree): self
    {
        $fibers = [];
        foreach ($tree->mounted() as $fiber) {
            $fibers[$fiber->id] = [$fiber->parent?->id, $fiber->holdsRoot ? null : self::hash($fiber->ownHtml[$fiber->pass] ?? '')];
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

    public static function hash(string $html): string
    {
        return hash('xxh128', $html);
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
}
