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

use Phem\ClassList;
use Twig\Markup;

/**
 * Renders the `class` attribute of an element: `phem_class()`, the `class` of `phem_attrs()`,
 * and the `class` of `<Root>`.
 *
 * A ClassList and a `class` array (the same inputs, as in clsx) go through the ClassMerger. A
 * string renders as it is. Null, false and an empty list render no attribute.
 */
final class ClassAttribute
{
    public function __construct(private readonly ClassMerger $merger = new UniqueClassMerger())
    {
    }

    public function render(mixed $value): Markup
    {
        if ($value instanceof ClassList || \is_array($value)) {
            $classes = ClassList::of($value)->classes;
            $value = [] !== $classes ? $this->merger->merge($classes) : null;
        }

        return Attributes::attr('class', $value);
    }
}
