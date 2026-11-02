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
 * The attributes of an event handler, for the middleware: the attributes of its method (a
 * first-class callable, `$this->save(...)`), or of its closure (`#[Validated] fn () => ...`).
 * ReflectionFunction gives both. It keeps the attributes of each method for the life of the
 * service.
 */
final class HandlerAttributes
{
    /** @var array<string, list<object>> */
    private array $methods = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(\Closure $handler, string $class): array
    {
        $function = new \ReflectionFunction($handler);
        $scope = $function->getClosureScopeClass();

        // A method has one name. Two closures on one line have the same name: no cache for them.
        $key = null !== $scope && $scope->hasMethod($function->getName()) ? $scope->name.'::'.$function->getName().'@'.$class : null;
        if (null !== $key && isset($this->methods[$key])) {
            /** @var list<T> $cached */
            $cached = $this->methods[$key];

            return $cached;
        }

        /** @var list<T> $attributes */
        $attributes = array_map(static fn (\ReflectionAttribute $a): object => $a->newInstance(), $function->getAttributes($class));
        if (null !== $key) {
            $this->methods[$key] = $attributes;
        }

        return $attributes;
    }
}
