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

namespace Phem\Rendering;

/**
 * A member of a component class that phemc does not see: a property or a method of a parent
 * class outside the .phem sources.
 *
 * phemc compiles `{{ title }}` in such a class to `Member::read($this, 'title', self::class)`:
 * the value of the property, else the method as a closure, else null. The member is read in the
 * scope of the class of the markup, as `$this->title` there.
 *
 * @internal
 */
final class Member
{
    /**
     * @param class-string $scope the class that declares the markup
     */
    public static function read(object $component, string $name, string $scope): mixed
    {
        $read = \Closure::bind(
            static fn (object $c): mixed => $c->{$name} ?? (method_exists($c, $name) ? $c->{$name}(...) : null),
            null,
            $scope,
        );

        return $read($component);
    }
}
