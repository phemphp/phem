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

namespace Phem\Manifest;

/**
 * A feed property of a class component in the phemc manifest (see Phem\Feed).
 */
final class FeedDefinition
{
    /**
     * @param string $target   the id of the element around the loop of the feed
     * @param string $template the template of one item (the loop body), without the Twig namespace
     * @param string $var      the loop variable: the name of the item in the template
     */
    public function __construct(
        public readonly string $target,
        public readonly string $template,
        public readonly string $var,
    ) {
    }

    /** @param array<string, mixed> $data an entry of the `feeds` list of the manifest */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['target'] ?? ''), (string) ($data['template'] ?? ''), (string) ($data['var'] ?? ''));
    }
}
