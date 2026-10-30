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

use TalesFromADev\TailwindMerge\TailwindMergeInterface;

/**
 * A ClassMerger for Tailwind CSS v4 (`tales-from-a-dev/tailwind-merge-php`): a later class removes
 * the earlier classes of the same group, so `px-2` and then `px-4` gives `px-4`.
 *
 * The service is shared, so the in-memory cache of TailwindMerge stays between the requests of a
 * worker.
 */
final class TailwindClassMerger implements ClassMerger
{
    public function __construct(private readonly TailwindMergeInterface $tailwind)
    {
    }

    public function merge(array $classes): string
    {
        return $this->tailwind->merge($classes);
    }
}
