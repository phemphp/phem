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

namespace Phem\Metadata;

use Phem\Attribute\Context;
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\Provide;
use Phem\Attribute\State;
use Phem\Binding\ComponentName;

/**
 * The context attributes of the components: the #[Context] properties and parameters (what a
 * component reads), and the #[Provide] properties and classes (what a component provides). It
 * keeps the data for the life of the service.
 *
 * The key of a context is its declared type: one class, interface or enum, nullable or not. A
 * scalar, an array, `Closure`, `object`, `mixed` or a union type cannot be a key.
 */
final class ContextMetadata
{
    /** @var array<string, array<string, ContextDependency>> */
    private array $properties = [];

    /** @var array<string, array<string, ContextDependency>> */
    private array $parameters = [];

    /** @var array<string, list<ProvidedContext>> */
    private array $provides = [];

    /**
     * The #[Context] properties of a class component.
     *
     * @param class-string $class
     *
     * @return array<string, ContextDependency> name => dependency
     *
     * @throws \LogicException when a #[Context] property is static, readonly, promoted, a prop or state, or has a type that is not a key
     */
    public function properties(string $class): array
    {
        if (isset($this->properties[$class])) {
            return $this->properties[$class];
        }

        $out = [];
        foreach (new \ReflectionClass($class)->getProperties() as $p) {
            if ([] === $p->getAttributes(Context::class)) {
                continue;
            }

            $what = \sprintf('The #[Context] property "%s" of <%s>', $p->getName(), ComponentName::short($class));
            if ($p->isStatic() || $p->isReadOnly() || $p->isPromoted() || [] !== $p->getAttributes(Prop::class) || [] !== $p->getAttributes(State::class) || [] !== $p->getAttributes(Path::class)) {
                throw new \LogicException($what.' must not be static, readonly, promoted, a prop or state: the context sets it in each render.');
            }

            $type = $p->getType();
            $hasDefault = $p->hasDefaultValue();
            $out[$p->getName()] = new ContextDependency(
                $p->getName(),
                self::key($type, $what),
                !$hasDefault && !($type?->allowsNull() ?? true),
                $hasDefault ? $p->getDefaultValue() : null,
                $p,
            );
        }

        return $this->properties[$class] = $out;
    }

    /**
     * The #[Context] parameters of a function component. They are not props.
     *
     * @return array<string, ContextDependency> name => dependency
     *
     * @throws \LogicException when a #[Context] parameter is variadic or has a type that is not a key
     */
    public function parameters(string $function): array
    {
        if (isset($this->parameters[$function])) {
            return $this->parameters[$function];
        }

        $out = [];
        foreach (new \ReflectionFunction($function)->getParameters() as $p) {
            if ([] === $p->getAttributes(Context::class)) {
                continue;
            }

            $what = \sprintf('The #[Context] parameter "%s" of <%s>', $p->getName(), ComponentName::short($function));
            if ($p->isVariadic()) {
                throw new \LogicException($what.' must not be variadic.');
            }

            $hasDefault = $p->isDefaultValueAvailable();
            $out[$p->getName()] = new ContextDependency(
                $p->getName(),
                self::key($p->getType(), $what),
                !$hasDefault && !$p->allowsNull(),
                $hasDefault ? $p->getDefaultValue() : null,
            );
        }

        return $this->parameters[$function] = $out;
    }

    /**
     * The contexts that a class component provides: its #[Provide] properties, and the component
     * itself for #[Provide] on the class (first).
     *
     * @param class-string $class
     *
     * @return list<ProvidedContext>
     *
     * @throws \LogicException when a #[Provide] property is static or has a type that is not a key
     */
    public function provides(string $class): array
    {
        if (isset($this->provides[$class])) {
            return $this->provides[$class];
        }

        $reflection = new \ReflectionClass($class);
        $out = [] !== $reflection->getAttributes(Provide::class) ? [new ProvidedContext($class)] : [];
        foreach ($reflection->getProperties() as $p) {
            if ([] === $p->getAttributes(Provide::class)) {
                continue;
            }

            $what = \sprintf('The #[Provide] property "%s" of <%s>', $p->getName(), ComponentName::short($class));
            if ($p->isStatic()) {
                throw new \LogicException($what.' must not be static.');
            }

            $out[] = new ProvidedContext(self::key($p->getType(), $what), $p);
        }

        return $this->provides[$class] = $out;
    }

    /**
     * The key of a context: one named class, interface or enum, nullable or not.
     *
     * @return class-string
     */
    private static function key(?\ReflectionType $type, string $what): string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || \in_array(strtolower($type->getName()), ['closure', 'self', 'static', 'parent'], true)) {
            throw new \LogicException(\sprintf('%s has the type "%s", but the key of a context is one class, interface or enum (nullable or not). Use an enum or a value object for a scalar, and provide the object that has a handler for a closure.', $what, $type ?? 'none'));
        }

        /** @var class-string */
        $name = $type->getName();

        return $name;
    }
}
