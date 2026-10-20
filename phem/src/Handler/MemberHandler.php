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

namespace Phem\Handler;

/**
 * A member path as a handler: `onClick={{ tabs.select }}` (`phem_method(tabs, 'select')`). In
 * Twig, `tabs.select` calls the method, so phemc compiles a member path in a handler attribute to
 * this function. A method is a closure on its object (`$tabs->select(...)`): the object is the
 * owner of the handler, as for a handler of a component (see Phem\Rendering\EventRenderer).
 *
 * A key of an array, or a property, keeps its value: a closure in a hash of props
 * (`row.onRemove`) is a handler too.
 */
final class MemberHandler
{
    public static function of(mixed $object, string $member): mixed
    {
        if (\is_array($object)) {
            return $object[$member] ?? null;
        }
        if (!\is_object($object)) {
            return null;
        }
        $method = [$object, $member];
        if (\is_callable($method)) {
            return \Closure::fromCallable($method);
        }

        return $object->{$member} ?? null;
    }
}
