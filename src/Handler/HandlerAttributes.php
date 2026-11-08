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
 * ReflectionFunction gives both. It also gives the attributes of the parameters
 * (`save(#[MapForm] Draft $draft)`). It keeps the attributes of each method for the life of the
 * service.
 */
final class HandlerAttributes
{
    /** @var array<string, list<object>> */
    private array $methods = [];

    /** @var array<string, array<string, array{object, string}>> */
    private array $parameters = [];

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
        $key = self::keyOf($function, $class);
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

    /**
     * The parameters of a handler with an attribute: name => the attribute and the type of the
     * parameter (a class name, or the type as PHP writes it).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return array<string, array{T, string}>
     */
    public function ofParameters(\Closure $handler, string $class): array
    {
        $function = new \ReflectionFunction($handler);
        $key = self::keyOf($function, $class);
        if (null !== $key && isset($this->parameters[$key])) {
            /** @var array<string, array{T, string}> $cached */
            $cached = $this->parameters[$key];

            return $cached;
        }

        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $attribute = $parameter->getAttributes($class)[0] ?? null;
            if (null !== $attribute) {
                $type = $parameter->getType();
                $parameters[$parameter->getName()] = [$attribute->newInstance(), $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type];
            }
        }

        if (null !== $key) {
            $this->parameters[$key] = $parameters;
        }

        return $parameters;
    }

    /** A method has one name. Two closures on one line have the same name: no cache for them. */
    private static function keyOf(\ReflectionFunction $function, string $class): ?string
    {
        $scope = $function->getClosureScopeClass();

        return null !== $scope && $scope->hasMethod($function->getName()) ? $scope->name.'::'.$function->getName().'@'.$class : null;
    }
}
