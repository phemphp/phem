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

use Phem\Html;
use Phem\HtmlList;
use Phem\LazyHtml;
use Phem\Metadata\ComponentMetadata;
use Phem\Metadata\Parameter;

/**
 * Binds props to the parameters of a function component or to the #[Prop] properties of a
 * class component, with defaults and clear errors.
 */
final class PropBinder
{
    public function __construct(private readonly ComponentMetadata $metadata = new ComponentMetadata())
    {
    }

    /**
     * Named arguments for a function component.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     *
     * @return array<string, mixed>
     */
    public function arguments(string $fqcn, array $props, array $slots = []): array
    {
        // A #[Context] parameter is not a prop: the context gives it (see ContextResolver).
        $params = array_filter($this->metadata->parameters($fqcn), static fn (Parameter $p): bool => !$p->context);
        $variadic = array_any($params, static fn (Parameter $p): bool => $p->variadic);
        $args = [];
        foreach ($props + $slots as $name => $value) {
            if (!isset($params[$name]) && !$variadic) {
                throw new PropError(\sprintf('<%s> has no prop "%s". Its props are: %s.', ComponentName::short($fqcn), $name, implode(', ', array_keys($params)) ?: 'none'));
            }
            $args[$name] = $value instanceof HtmlList && ($params[$name]->wantsArray ?? false) ? $value->toArray() : $value;
        }
        foreach ($params as $name => $p) {
            if (!$p->variadic && !\array_key_exists($name, $args) && !$p->optional) {
                if (!$p->nullable) {
                    throw new PropError(\sprintf('<%s> needs the prop "%s".', ComponentName::short($fqcn), $name));
                }
                $args[$name] = null;
            }
        }

        return $args;
    }

    /**
     * The context of a template component: the props, then the parameter defaults. As in a
     * PHP call, the variadic parameter (`...$rest`) gets the props that no parameter takes.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     *
     * @return array<string, mixed>
     */
    public function templateContext(string $fqcn, array $props, array $slots = []): array
    {
        $params = array_filter($this->metadata->parameters($fqcn), static fn (Parameter $p): bool => !$p->context);
        $vars = [];
        $rest = [];
        foreach ($this->arguments($fqcn, $props, $slots) as $name => $value) {
            if (isset($params[$name]) && !$params[$name]->variadic) {
                $vars[$name] = $value;
            } else {
                $rest[$name] = $value;
            }
        }
        foreach ($params as $name => $p) {
            if ($p->variadic) {
                $vars[$name] = $rest;
            } elseif (!\array_key_exists($name, $vars)) {
                $vars[$name] = $p->default;
            }
        }

        return $vars;
    }

    /**
     * Whether a readonly prop of a class component that is set gets another value: assign()
     * cannot write it, so the component needs a new instance.
     *
     * Markup that renders on first use (a slot, a markup literal) counts as another value: to
     * compare it, it must render before render(), so its components get other fiber ids.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     */
    public function changesReadonly(object $component, array $props, array $slots = []): bool
    {
        $all = $props + $slots;
        $properties = $this->metadata->props($component::class);
        foreach ($properties as $name => $p) {
            if (!$p->isReadOnly() || !$p->isInitialized($component) || !\array_key_exists($name, $all)) {
                continue;
            }
            if (self::differs($p->getValue($component), self::value($p, $all[$name]))) {
                return true;
            }
        }
        $rest = $this->metadata->restProp($component::class);

        return null !== $rest && $rest->isReadOnly() && $rest->isInitialized($component)
            && self::differs($rest->getValue($component), array_diff_key($all, $properties));
    }

    /**
     * Writes the props (and slots) into a class component. A missing prop gets its default,
     * and the rest prop gets the props that no other prop takes. A readonly prop that is set
     * keeps its value (see changesReadonly()).
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     */
    public function assign(object $component, array $props, array $slots = []): void
    {
        $class = $component::class;
        $all = $props + $slots;
        $properties = $this->metadata->props($class);
        $rest = $this->metadata->restProp($class);
        foreach ($all as $name => $_) {
            if (!isset($properties[$name]) && null === $rest) {
                throw new PropError(\sprintf('<%s> has no prop "%s". Its props are: %s.', ComponentName::short($class), $name, implode(', ', array_keys($properties)) ?: 'none'));
            }
        }
        foreach ($properties as $name => $p) {
            if ($p->isReadOnly() && $p->isInitialized($component)) {
                continue;
            }
            if (\array_key_exists($name, $all)) {
                $p->setValue($component, self::value($p, $all[$name]));
            } elseif ($p->hasDefaultValue()) {
                $p->setValue($component, $p->getDefaultValue());
            } elseif ($p->getType()?->allowsNull() ?? true) {
                $p->setValue($component, null);
            } elseif (!$p->isInitialized($component)) {
                throw new PropError(\sprintf('<%s> needs the prop "%s".', ComponentName::short($class), $name));
            }
        }
        if (null !== $rest && !($rest->isReadOnly() && $rest->isInitialized($component))) {
            $rest->setValue($component, array_diff_key($all, $properties));
        }
    }

    /**
     * Whether a readonly prop gets another value. Markup that renders on first use counts as
     * another value, and a hash (the rest prop) differs when one of its values differs.
     */
    private static function differs(mixed $old, mixed $new): bool
    {
        if ($old instanceof LazyHtml || $new instanceof LazyHtml) {
            return true;
        }
        if ($old instanceof Html && $new instanceof Html) {
            return (string) $old !== (string) $new;
        }
        if (\is_array($old) && \is_array($new) && array_keys($old) === array_keys($new)) {
            return array_any($old, static fn (mixed $v, int|string $k): bool => self::differs($v, $new[$k]));
        }

        return $old !== $new;
    }

    /** The value of a prop for a property: a list of markup is an array for an `array` property. */
    private static function value(\ReflectionProperty $p, mixed $value): mixed
    {
        return $value instanceof HtmlList && HtmlList::wantsArray($p->getType()) ? $value->toArray() : $value;
    }
}
