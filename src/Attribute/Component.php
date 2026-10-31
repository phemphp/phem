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

namespace Phem\Attribute;

/**
 * Marks a class or a function as a component. A class must have a `render()` method; its
 * #[Prop] properties are the props. The parameters of a function are its props. A function
 * without this attribute is a plain PHP function, also in a .phpm file.
 *
 * With `replaces`, a component of the app replaces a component (of a package, for example) in
 * all the tags of the project. Inside the replacement (also in its parent classes and its traits),
 * a tag of the original stays the original.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION)]
final class Component
{
    /**
     * @param string|null $replaces the class name of the component that this component replaces
     */
    public function __construct(
        public readonly ?string $replaces = null,
    ) {
    }
}
