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
 * - With props with #[BroadcastKey], the key of the instance: the component and the values of
 *   these props (see NameValues). An entity is its identifier, an enum is its value.
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
        ?ObjectIdentifier $objects = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
        $this->values = new NameValues($objects);
    }

    /**
     * The keys of a fiber for its `data-phem-broadcast`: the key of its component, and the key
     * of the instance when it has #[BroadcastKey] props. None when the component has no
     * #[Broadcast].
     *
     * @return list<string>
     */
    public function ofFiber(Fiber $fiber): array
    {
        $props = $this->props($fiber->fqcn);
        if (null === $props) {
            return [];
        }

        $keys = [$this->ofComponent($fiber->fqcn)];
        if ([] !== $props) {
            // The values of the component itself: no check of their names.
            $keys[] = $this->instance($fiber->fqcn, $this->valuesOf($fiber));
        }

        return $keys;
    }

    /**
     * The values of the key props of a fiber: the props of a class component after its render,
     * or the props that the parent gave a function component, with the default values of its
     * parameters. A prop with no value is null.
     *
     * @return array<string, mixed>
     */
    public function valuesOf(Fiber $fiber): array
    {
        $parameters = null === $fiber->instance ? $this->metadata->parameters($fiber->fqcn) : [];

        $values = [];
        foreach ($this->props($fiber->fqcn) ?? [] as $name) {
            $values[$name] = match (true) {
                null !== $fiber->instance => $fiber->instance->{$name} ?? null,
                \array_key_exists($name, $fiber->props) => $fiber->props[$name],
                // The parent did not give it: the default value of the parameter.
                default => $parameters[$name]->default,
            };
        }

        return $values;
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
            throw new \LogicException(\sprintf('The component "%s" has no #[BroadcastKey] prop: give no values to refresh all its instances, or mark the props that identify an instance with #[BroadcastKey].', $component));
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
     * The key props of a component with #[Broadcast] (its props with #[BroadcastKey], in their
     * order), or null with no #[Broadcast] or for a page.
     *
     * @return list<string>|null
     *
     * @throws \LogicException when a #[BroadcastKey] is not on a prop of a component with #[Broadcast]
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

        $props = class_exists($component) ? $this->metadata->props($component) : array_filter($this->metadata->parameters($component), static fn (Parameter $p): bool => !$p->context);
        if ([] !== $other = array_diff($keys, array_keys($props))) {
            throw new \LogicException(\sprintf('The property "%s" of the component "%s" has #[%s], but it is not a prop: put it on a #[Prop].', reset($other), $component, BroadcastKey::class));
        }

        return $this->props[$component] = $keys;
    }


    /** @param array<string, mixed> $values the values of all the key props */
    private function instance(string $component, array $values): string
    {
        $normalized = $this->values->normalize($values, static fn (string $name, mixed $value): \Throwable => new \LogicException(\sprintf('The key prop "%s" of the component "%s" is a "%s": a key prop is a scalar, an enum, an array of them, or an entity.', $name, $component, get_debug_type($value))));

        return NameHash::of($this->secret, 'broadcast:'.$component.'?'.http_build_query($normalized, '', '&', \PHP_QUERY_RFC3986));
    }
}
