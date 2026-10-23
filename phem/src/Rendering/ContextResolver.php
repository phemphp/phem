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

use Phem\Binding\ComponentName;
use Phem\Binding\ContextError;
use Phem\Metadata\ContextDependency;
use Phem\Metadata\ContextMetadata;

/**
 * Gives the components their contexts (see Phem\Attribute\Context and Phem\Attribute\Provide).
 *
 * The lookup walks up the fibers (Fiber::$parent: a slot renders in the fiber of the component
 * that prints it) and takes the nearest provider whose value is an instance of the type. It reads
 * the provider when the consumer renders: in the second pass of an event, a dirty consumer
 * renders without its parent, and it reads the value of its provider after the handler.
 *
 * A provider records what it provides before its children render, and whether it changed since
 * the pass before: then UpdateScope renders its consumers again, also under kept fibers.
 *
 * @internal
 */
final class ContextResolver
{
    public function __construct(private readonly ContextMetadata $metadata = new ContextMetadata())
    {
    }

    /** Writes the #[Context] properties of a class component. */
    public function assign(object $component, Fiber $fiber): void
    {
        foreach ($this->metadata->properties($component::class) as $dependency) {
            $dependency->property?->setValue($component, $this->value($fiber, $dependency));
        }
    }

    /**
     * The #[Context] arguments of a function component.
     *
     * @return array<string, mixed>
     */
    public function arguments(string $function, Fiber $fiber): array
    {
        $args = [];
        foreach ($this->metadata->parameters($function) as $name => $dependency) {
            $args[$name] = $this->value($fiber, $dependency);
        }

        return $args;
    }

    /**
     * Records the contexts that a fiber provides in this pass, before its children render. They
     * changed when the fiber did not provide them in the pass before, when a value is the
     * component itself or a mutable object, and when an enum or a readonly object is another one.
     */
    public function provide(Fiber $fiber): void
    {
        $component = $fiber->instance;
        $provides = null !== $component ? $this->metadata->provides($component::class) : [];
        if ([] === $provides) {
            return;
        }

        $values = [];
        foreach ($provides as $provided) {
            $values[$provided->type] ??= $provided->valueOf($component);
        }

        $fiber->changesContext = $fiber->providedPass !== $fiber->pass - 1 || !self::same($fiber->provided, $values);
        $fiber->provided = $values;
        $fiber->providedPass = $fiber->pass;
    }

    private function value(Fiber $fiber, ContextDependency $dependency): mixed
    {
        for ($provider = $fiber->parent; null !== $provider; $provider = $provider->parent) {
            $component = $provider->instance;
            if (null === $component) {
                continue;
            }

            foreach ($this->metadata->provides($component::class) as $provided) {
                $value = $provided->valueOf($component);
                if ($value instanceof $dependency->type) {
                    $fiber->contexts[$dependency->type] = $provider->id;

                    return $value;
                }
            }
        }

        if ($dependency->required) {
            $path = [];
            for ($f = $fiber; null !== $f; $f = $f->parent) {
                array_unshift($path, '<'.ComponentName::short($f->fqcn).'>');
            }

            throw new ContextError(\sprintf('<%1$s> needs a "%2$s" context for "%3$s", but no component above it provides one. Render <%1$s> inside a component with #[Provide] for this type, or give "%3$s" a default value. Path: %4$s.', ComponentName::short($fiber->fqcn), $dependency->type, $dependency->name, implode(' > ', $path)));
        }

        return $dependency->default;
    }

    /**
     * Whether two provided values are the same: enums and scalars are the same when they are
     * identical, and readonly objects when they have the same class and the same values. A mutable
     * object is never the same: a handler can change it in place.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (!self::same($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (\is_object($a) && !$a instanceof \UnitEnum) {
            return \is_object($b) && $a::class === $b::class && new \ReflectionClass($a)->isReadOnly() && self::same((array) $a, (array) $b);
        }

        return $a === $b;
    }
}
