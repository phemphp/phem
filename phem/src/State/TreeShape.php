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
 * The shape of the last render of a page: for each fiber, the id of its parent, the hash of its
 * own HTML (the HTML without the content of its child fibers, see Fiber::$ownHtml), and whether
 * it read a context (a consumer, see Phem\Rendering\ContextResolver).
 *
 * The browser keeps it in the signed state. With it, an event knows the render from before the
 * handler with no second render, and it knows the path from the page to a fiber.
 *
 * A fiber around the root element (see Fiber::$holdsRoot) has no hash: it is not in the
 * browser, and its HTML has the random token of the root element.
 *
 * In the state, the shape is one string (see encode()), because each event sends it again: the
 * fibers from the page down, in their order, separated by a comma. Each fiber is its id, its
 * hash, and its depth (0 for the page). The parent of a fiber is the last fiber before it with
 * one less depth. The id is the 48 bits of the fiber id (see Fiber::idOf()) and the hash is 48
 * bits, each in 8 base64url characters. A fiber with no hash has no hash characters. A consumer
 * ends with `c`.
 */
final class TreeShape
{
    private const FIBER_PREFIX = 'phem';

    private const CONSUMER = 'c';

    /** @var array<string, list<string>>|null parent id => child ids */
    private ?array $children = null;
    /** @var array<string, true>|null the consumers and their ancestors */
    private ?array $toConsumers = null;

    /**
     * @param array<string, array{0: string|null, 1: string|null, 2?: true}> $fibers fiber id => [parent id, hash, and true for a consumer]
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
            $fibers[$id] = null !== $fiber
                ? [$parent, $fiber->holdsRoot ? null : self::hash($fiber->ownHtml[$fiber->pass] ?? '')]
                : [$parent, $tree->shape->hashOf($id)];
            if (null !== $fiber ? [] !== $fiber->contexts : $tree->shape->isConsumer($id)) {
                $fibers[$id][] = true;
            }
        }

        return new self($fibers);
    }

    /**
     * The shape in the string of the state (see encode()). A value with another form gives an
     * empty shape: then the event renders the full page.
     *
     * @param mixed $data the value of the `_tree` key
     */
    public static function decode(mixed $data): self
    {
        if (!\is_string($data) || '' === $data) {
            return new self();
        }
        $fibers = [];
        $ancestors = [];
        foreach (explode(',', $data) as $entry) {
            // 8 characters of id, 8 characters of hash or none, the depth (8 digits or less), then
            // `c` for a consumer.
            $consumer = str_ends_with($entry, self::CONSUMER);
            if ($consumer) {
                $entry = substr($entry, 0, -1);
            }
            $length = \strlen($entry);
            $hash = $length > 16 ? substr($entry, 8, 8) : null;
            $depth = substr($entry, null !== $hash ? 16 : 8);
            if (1 !== preg_match('/^[A-Za-z0-9_-]{8}$/', $code = substr($entry, 0, 8)) || (null !== $hash && 1 !== preg_match('/^[A-Za-z0-9_-]{8}$/', $hash))
                || !ctype_digit($depth) || (int) $depth > \count($ancestors) || isset($fibers[$id = self::FIBER_PREFIX.bin2hex(self::fromBase64($code))])) {
                return new self();
            }
            $depth = (int) $depth;
            $fibers[$id] = $consumer ? [$ancestors[$depth - 1] ?? null, $hash, true] : [$ancestors[$depth - 1] ?? null, $hash];
            $ancestors = \array_slice($ancestors, 0, $depth);
            $ancestors[] = $id;
        }

        return new self($fibers);
    }

    /**
     * The shape as one string, for the state (see the class).
     */
    public function encode(): string
    {
        $entries = [];
        foreach ($this->fibers as $id => [$parent]) {
            if (null === $parent || !$this->has($parent)) {
                $this->encodeFrom($id, 0, $entries);
            }
        }

        return implode(',', $entries);
    }

    /**
     * A 48-bit hash in 8 base64url characters: it only tells whether the HTML of a fiber
     * changed, and the state stays small.
     */
    public static function hash(string $html): string
    {
        return self::toBase64(substr(hash('xxh3', $html, true), 0, 6));
    }

    /** The hash of the own HTML of a fiber in the last render, or null. */
    public function hashOf(string $id): ?string
    {
        return $this->fibers[$id][1] ?? null;
    }

    /** Whether a fiber read a context in the last render. */
    public function isConsumer(string $id): bool
    {
        return $this->fibers[$id][2] ?? false;
    }

    /**
     * Whether a fiber is a consumer or has one in its subtree, in the last render: a change of a
     * context above it must render it (see Phem\Rendering\UpdateScope).
     */
    public function leadsToConsumer(string $id): bool
    {
        if (null === $this->toConsumers) {
            $this->toConsumers = [];
            foreach (array_keys($this->fibers) as $fiber) {
                for ($current = $this->isConsumer($fiber) ? $fiber : null; null !== $current && !isset($this->toConsumers[$current]); $current = $this->parentOf($current)) {
                    $this->toConsumers[$current] = true;
                }
            }
        }

        return isset($this->toConsumers[$id]);
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

    /** @param list<string> $entries */
    private function encodeFrom(string $id, int $depth, array &$entries): void
    {
        if (1 !== preg_match('/^'.self::FIBER_PREFIX.'([0-9a-f]{12})$/', $id, $m)) {
            throw new \LogicException(\sprintf('The fiber id "%s" is not an id of Fiber::idOf().', $id));
        }
        $entries[] = self::toBase64((string) hex2bin($m[1])).$this->hashOf($id).$depth.($this->isConsumer($id) ? self::CONSUMER : '');
        foreach ($this->childrenOf($id) as $child) {
            $this->encodeFrom($child, $depth + 1, $entries);
        }
    }

    private static function toBase64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function fromBase64(string $code): string
    {
        return (string) base64_decode(strtr($code, '-_', '+/'), true);
    }

    /**
     * @param array<string, array{0: string|null, 1: string|null, 2?: true}> $fibers
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
