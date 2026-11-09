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

namespace Phem\Stream;

use Phem\Attribute\Broadcast;
use Phem\Attribute\BroadcastKey;
use Phem\Handler\ObjectIdentifier;
use Phem\Metadata\ComponentMetadata;
use Phem\Metadata\Parameter;
use Phem\Rendering\Fiber;

/**
 * The keys of the instances of a component with #[Broadcast]: the element with the fiber id of
 * each instance has them in `data-phem-broadcast`, and a refresh with no target selects them (see
 * PageStreams::refresh()).
 *
 * - The key of the component: all its instances.
 * - With #[BroadcastKey] on props or properties, the key of the instance: the component and the
 *   values of these keys after the render (see NameValues). An entity is its identifier, an enum
 *   is its value. A class component can mark any property, also a private one that mount() sets.
 * - With #[Broadcast(on: ...)] and more than one key prop, the key of each entity key prop alone:
 *   a change of one entity selects these instances (see EntityRefreshes). With one key prop,
 *   this is the key of the instance.
 *
 * A key is an HMAC with the secret of Phem, as a topic (see NameHash): the page does not show the
 * class names or the values of the props.
 */
final class BroadcastKeys
{
    public const ATTRIBUTE = 'data-phem-broadcast';

    /** @var array<string, list<string>|null> component => the key props, or null with no #[Broadcast] */
    private array $props = [];
    /** @var array<string, string> component => the key of all its instances */
    private array $components = [];
    private readonly NameValues $values;

    /**
     * @param string $secret the key of the HMAC (`phem.secret`)
     */
    public function __construct(
        private readonly string $secret = '',
        private readonly ?ObjectIdentifier $objects = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
        $this->values = new NameValues($objects);
    }

    /**
     * The keys of a fiber for its `data-phem-broadcast`: the key of its component, and the key
     * of the instance when it has #[BroadcastKey] props or properties. A replacement also has the keys of the
     * component that it replaces (`#[Component(replaces: ...)]`), with the same props. None when
     * the components have no #[Broadcast].
     *
     * @return list<string>
     */
    public function ofFiber(Fiber $fiber): array
    {
        $keys = [];
        foreach ([$fiber->fqcn, $this->metadata->replaces($fiber->fqcn)] as $component) {
            if (null === $component || null === $props = $this->props($component)) {
                continue;
            }

            $keys[] = $this->ofComponent($component);
            if ([] === $props) {
                continue;
            }

            // The values of the component itself: no check of their names.
            $values = $this->valuesOf($fiber, $component);
            $keys[] = $this->instance($component, $values);

            // One entity selects the instances of a component with more key props (see EntityRefreshes).
            if (\count($values) > 1 && [] !== $this->metadata->entityBroadcasts($component)) {
                foreach ($values as $name => $value) {
                    if (\is_object($value) && null !== $this->objects?->identify($value)) {
                        $keys[] = $this->instance($component, [$name => $value]);
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * The key of the instances with an entity in one key prop: the key of the instance of a
     * component with one key prop, else the key of this prop alone (see ofFiber()).
     *
     * @internal see EntityRefreshes
     */
    public function ofProp(string $component, string $prop, object $entity): string
    {
        return $this->instance($component, [$prop => $entity]);
    }

    /**
     * The values of the keys of a fiber: the properties of a class component after its render
     * (also a private one, null when it has no value), or the props that the parent gave a
     * function component, with the default values of its
     * parameters. A prop with no value is null. For the keys of the component that the fiber
     * replaces, a prop that the replacement takes in its rest prop has the value that the parent
     * gave, or the default value in the original.
     *
     * @param string|null $component the component of the key props: the component of the fiber, or the component that it replaces
     *
     * @return array<string, mixed>
     */
    public function valuesOf(Fiber $fiber, ?string $component = null): array
    {
        $component ??= $fiber->fqcn;

        $values = [];
        foreach ($this->props($component) ?? [] as $name) {
            $values[$name] = match (true) {
                null !== $fiber->instance && property_exists($fiber->instance, $name) => self::read($fiber->instance, $name),
                \array_key_exists($name, $fiber->props) => $fiber->props[$name],
                // The parent did not give it: the default value of the prop.
                default => $this->defaultOf($component, $name),
            };
        }

        return $values;
    }

    /** The value of a property after the render, also a private one, or null when it has none. */
    private static function read(object $instance, string $name): mixed
    {
        $property = new \ReflectionProperty($instance, $name);

        return $property->isInitialized($instance) ? $property->getValue($instance) : null;
    }

    /** The default value of a prop of a component: a parameter of a function, or a #[Prop] property. */
    private function defaultOf(string $component, string $name): mixed
    {
        if (\function_exists($component)) {
            return ($this->metadata->parameters($component)[$name] ?? null)?->default;
        }

        $property = class_exists($component) ? $this->metadata->props($component)[$name] ?? null : null;

        return null !== $property && $property->hasDefaultValue() ? $property->getDefaultValue() : null;
    }

    /** The key of all the instances of a component. */
    public function ofComponent(string $component): string
    {
        return $this->components[$component] ??= NameHash::of($this->secret, 'broadcast:'.$component);
    }

    /**
     * The key of the instances with these values of the key props.
     *
     * @param array<string, mixed> $values
     *
     * @throws \LogicException when the values are not the key props of the component
     */
    public function ofInstance(string $component, array $values): string
    {
        $props = $this->props($component) ?? [];
        if ([] === $props) {
            throw new \LogicException(\sprintf('The component "%s" has no #[BroadcastKey]: give no values to refresh all its instances, or mark the props or the properties that identify an instance with #[BroadcastKey].', $component));
        }

        if ([] !== $unknown = array_diff_key($values, array_flip($props))) {
            throw new \LogicException(\sprintf('The key of the component "%s" has no prop "%s": give the values of its key props ("%s").', $component, implode('", "', array_keys($unknown)), implode('", "', $props)));
        }

        if ([] !== $missing = array_diff($props, array_keys($values))) {
            throw new \LogicException(\sprintf('The refresh of one instance of the component "%s" needs the values of all its key props, but "%s" is missing. Give no values to refresh all its instances.', $component, implode('", "', $missing)));
        }

        return $this->instance($component, $values);
    }

    /**
     * The keys of a component with #[Broadcast] (its properties or parameters with
     * #[BroadcastKey], in their order), or null with no #[Broadcast] or for a page.
     *
     * @return list<string>|null
     *
     * @throws \LogicException when a #[BroadcastKey] is not on a component with #[Broadcast], on a
     *                         parameter of a function component that is not a prop, or on a property
     *                         that has no value in each render
     */
    public function props(string $component): ?array
    {
        if (\array_key_exists($component, $this->props)) {
            return $this->props[$component];
        }

        // A page has no instances on other pages: it has no key.
        $keys = $this->metadata->broadcastKeys($component);
        $keyed = $this->metadata->isComponent($component) && $this->metadata->broadcasts($component);

        if ([] !== $keys && !$keyed) {
            throw new \LogicException(\sprintf('The prop "%s" of "%s" has #[%s], but only a component with #[%s] has keys: add #[Broadcast] to the component or to its handlers, or remove #[BroadcastKey].', $keys[0], $component, BroadcastKey::class, Broadcast::class));
        }

        if (!$keyed) {
            return $this->props[$component] = null;
        }

        if (class_exists($component)) {
            $this->checkProperties($component, $keys);

            return $this->props[$component] = $keys;
        }

        $props = array_filter($this->metadata->parameters($component), static fn (Parameter $p): bool => !$p->context);
        if ([] !== $other = array_diff($keys, array_keys($props))) {
            throw new \LogicException(\sprintf('The parameter "%s" of the component "%s" has #[%s], but it is not a prop: a function component gets its key from the props of its parent.', reset($other), $component, BroadcastKey::class));
        }

        return $this->props[$component] = $keys;
    }

    /**
     * A key property of a class component has a value after each render: a #[Prop] or a #[State],
     * or any property when the component has no #[State] (its mount() runs in each render).
     *
     * @param class-string $component
     * @param list<string> $keys
     *
     * @throws \LogicException when a component with #[State] has a key property that only mount() sets
     */
    private function checkProperties(string $component, array $keys): void
    {
        $states = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $this->metadata->stateProperties($component));
        if ([] === $states) {
            return;
        }

        $kept = [...array_keys($this->metadata->props($component)), ...$states];
        if ([] !== $other = array_diff($keys, $kept)) {
            throw new \LogicException(\sprintf('The property "%s" of the component "%s" has #[%s], but the component has #[State]: its mount() runs only at the first render, so the property has no value in the next events. Put #[Prop] or #[State] on the property.', reset($other), $component, BroadcastKey::class));
        }
    }


    /** @param array<string, mixed> $values the values of all the key props */
    private function instance(string $component, array $values): string
    {
        $normalized = $this->values->normalize($values, static fn (string $name, mixed $value): \Throwable => new \LogicException(\sprintf('The key prop "%s" of the component "%s" is a "%s": a key prop is a scalar, an enum, an array of them, or an entity.', $name, $component, get_debug_type($value))));

        return NameHash::of($this->secret, 'broadcast:'.$component.'?'.http_build_query($normalized, '', '&', \PHP_QUERY_RFC3986));
    }
}
