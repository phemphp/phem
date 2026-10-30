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
 * The default ClassMerger: removes the duplicates, and keeps the place of the first one.
 */
final class UniqueClassMerger implements ClassMerger
{
    public function merge(array $classes): string
    {
        return implode(' ', array_unique($classes));
    }
}
