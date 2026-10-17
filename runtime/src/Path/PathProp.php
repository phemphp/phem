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

namespace Mamu\Path;

/**
 * A #[Path] property of a page, with a named type.
 */
final class PathProp
{
    public function __construct(
        public readonly string $page,
        public readonly string $name,
        public readonly \ReflectionNamedType $type,
    ) {
    }
}
