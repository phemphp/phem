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

use Phem\Binding\ComponentName;
use Phem\Rendering\Tree;
use Psr\Log\LoggerInterface;

/**
 * Checks that the browser can send the state of a page back. After a miss of the state cache
 * (see StateCache), or with no cache, an event sends the full state in its body, and the body
 * has a maximum size (the `phem.max_event_size` option, see Phem\Http\EventBody). A larger state
 * renders, but its events fail with a 413 when the pool does not have it.
 *
 * In debug mode, the render of the page or of the event throws. In production, it logs an error:
 * the page works while the pool has its state.
 */
final class StateSizeLimit
{
    /**
     * @param int  $maxEventSize the `phem.max_event_size` option: the maximum bytes of the JSON of an event, 0 for no limit
     * @param bool $debug        `kernel.debug`: throw, not log
     */
    public function __construct(
        private readonly int $maxEventSize = 1048576,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The bytes of a state in the JSON body of an event: a JSON string, where each quote, each
     * backslash and each line break takes one byte more. A text of StateJson::encode() has no
     * other character that JSON escapes.
     */
    public static function bodySize(string $json): int
    {
        return \strlen($json) + substr_count($json, '"') + substr_count($json, '\\') + substr_count($json, "\n");
    }

    /**
     * @param string $json the signed state of the tree (see StateJson::encode())
     *
     * @throws \LogicException in debug mode, when the state is too large
     */
    public function check(string $json, Tree $tree): void
    {
        // The body size is two times the text at most: most states need no count.
        if (0 === $this->maxEventSize || 2 * \strlen($json) < $this->maxEventSize) {
            return;
        }

        $size = self::bodySize($json);
        if ($size < $this->maxEventSize) {
            return;
        }

        [$entry, $entrySize] = self::largest($json, $tree);
        $message = \sprintf('The state of the page "%s" takes %d bytes in the body of an event, but an event body can have a maximum of %d bytes (the phem.max_event_size option): after a miss of the state cache, the browser cannot send the state back, and the event fails with a 413. The largest entry is %s (%d bytes). Keep less in #[State] (an id, not the rows), or raise phem.max_event_size.', $tree->page, $size, $this->maxEventSize, $entry, $entrySize);

        if ($this->debug) {
            throw new \LogicException($message);
        }

        $this->logger?->error($message, ['page' => $tree->page, 'size' => $size, 'max_event_size' => $this->maxEventSize]);
    }

    /** @return array{string, int} the name of the largest entry of the state, and its body size */
    private static function largest(string $json, Tree $tree): array
    {
        $key = '';
        $size = 0;
        foreach (StateJson::entries($json) ?? [] as $k => $value) {
            $s = self::bodySize($value);
            if ($s > $size) {
                [$key, $size] = [$k, $s];
            }
        }

        $fiber = $tree->fiberById($key);

        return [null !== $fiber ? 'the state of <'.ComponentName::short($fiber->fqcn).'>' : 'the entry "'.$key.'"', $size];
    }
}
