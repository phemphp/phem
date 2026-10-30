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
 * Merges the classes of a `class` attribute when it renders (see ClassAttribute). The classes
 * are in order: a later class wins a conflict with an earlier class.
 *
 * The application selects the implementation with the `phem.class_merger` option.
 */
interface ClassMerger
{
    /**
     * @param list<string> $classes one class in each item, in order, with the duplicates
     *
     * @return string the value of the attribute
     */
    public function merge(array $classes): string;
}
