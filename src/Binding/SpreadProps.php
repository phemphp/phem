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

namespace Phem\Binding;

use Phem\ClassList;

/**
 * The props of a component tag with a spread, in one call: `<Inner x="1" {{ ...rest }} y={{ v }} />`
 * → `phem_props({'x': '1'}, rest, {'y': (v)})`.
 *
 * As in React, a later value replaces an earlier value of the same prop. Two values of `class`
 * (or of `className`) merge when one of them is a ClassList (see ClassList::merge()), so a
 * wrapper keeps its classes: `<Inner className={{ classes('x') }} {{ ...rest }} />`.
 */
final class SpreadProps
{
    private const CLASS_PROPS = ['class' => true, 'className' => true];

    /**
     * @return array<string, mixed>
     */
    public static function merge(mixed ...$groups): array
    {
        $props = [];
        foreach ($groups as $group) {
            if (null !== $group && !is_iterable($group)) {
                throw new \InvalidArgumentException(\sprintf('A spread (`{{ ...props }}`) needs a hash of props, "%s" given.', get_debug_type($group)));
            }

            foreach ($group ?? [] as $name => $value) {
                $name = (string) $name;
                $props[$name] = isset(self::CLASS_PROPS[$name]) && \array_key_exists($name, $props) ? ClassList::merge($props[$name], $value) : $value;
            }
        }

        return $props;
    }
}
