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

namespace Phem\Dom;

/**
 * The check of a tag name from a value (`<Root tag>`, `<Dynamic tag>`): an HTML element name,
 * with letters, digits and `-`. A name that starts with `phem-` is an element of Phem (the
 * `<phem-c>` of a fiber, the `<phem-suspense>` of a fallback), so the application cannot use it.
 *
 * @internal
 */
final class TagName
{
    /** @phpstan-assert-if-true =string $tag */
    public static function isValid(mixed $tag): bool
    {
        return \is_string($tag) && 1 === preg_match('/\A[a-z][a-z0-9-]*\z/i', $tag) && 0 !== stripos($tag, 'phem-');
    }
}
