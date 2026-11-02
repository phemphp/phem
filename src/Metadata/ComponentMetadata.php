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

use Phem\Attribute\Broadcast;
use Phem\Attribute\BroadcastKey;
use Phem\Attribute\Component;
use Phem\Attribute\Page;
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\State;
use Phem\Attribute\Url;
use Phem\Binding\ComponentName;
use Phem\Binding\PropError;
use Phem\State\StateValue;
use Phem\State\UrlQuery;
use Phem\Validation;

/**
 * The reflection data of the components: the parameters of a function component, the #[Prop]
 * and #[State] properties of a class component, and its #[Broadcast]. It keeps the data for the life of the
 * service.
 */
final class ComponentMetadata
{
    /** @var array<string, array<string, Parameter>> */
    private array $parameters = [];

    /** @var array<string, bool> */
    private array $broadcasts = [];

    /** @var array<string, list<string>> */
    private array $broadcastHandlers = [];

    /** @var array<string, list<string>> */
    private array $broadcastKeys = [];

    /** @var array<string, bool> */
    private array $components = [];

    /** @var array<string, string|null> */
    private array $replaces = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $props = [];

    /** @var array<string, ?\ReflectionProperty> */
    private array $rests = [];

    /** @var array<string, list<\ReflectionProperty>> */
    private array $states = [];

    /** @var array<string, list<array{\ReflectionProperty, class-string<StateValue>}>> */
    private array $stateValues = [];

    /** @var array<string, ?\ReflectionProperty> */
    private array $validations = [];

    /** @var array<string, array<string, ?\ReflectionType>> */
    private array $paths = [];

    /** @var array<string, array<string, array{0: \ReflectionProperty, 1: Url}>> */
    private array $urls = [];

    /**
     * The parameters of a function component: its props.
     *
     * @return array<string, Parameter>
     */
    public function parameters(string $function): array
    {
        if (isset($this->parameters[$function])) {
            return $this->parameters[$function];
        }

        if (!\function_exists($function)) {
            throw new PropError(\sprintf('Component function "%s" does not exist. Run `phemc build` again.', $function));
        }

        $out = [];
        foreach ((new \ReflectionFunction($function))->getParameters() as $p) {
            $out[$p->getName()] = Parameter::fromReflection($p);
        }

        return $this->parameters[$function] = $out;
    }

    /**
     * The props of a class component: its #[Prop] properties, and the #[Path] properties of a
     * page. A prop is public, not static, not promoted, and not #[State]. The rest prop is not
     * in the list (see restProp()).
     *
     * @param class-string $class
     *
     * @return array<string, \ReflectionProperty>
     */
    public function props(string $class): array
    {
        if (isset($this->props[$class])) {
            return $this->props[$class];
        }

        $out = [];
        $rest = null;
        foreach ((new \ReflectionClass($class))->getProperties() as $p) {
            $prop = $p->getAttributes(Prop::class)[0] ?? null;
            if (null === $prop && [] === $p->getAttributes(Path::class)) {
                continue;
            }

            if (!$p->isPublic() || $p->isStatic() || $p->isPromoted() || [] !== $p->getAttributes(State::class)) {
                $attribute = null !== $prop ? 'Prop' : 'Path';
                throw new PropError(\sprintf('The #[%s] property "%s" of <%s> must be a prop: public, not static, not promoted, and not #[State].', $attribute, $p->getName(), ComponentName::short($class)));
            }

            if (!($prop?->newInstance()->rest ?? false)) {
                $out[$p->getName()] = $p;
                continue;
            }

            if (null !== $rest) {
                throw new PropError(\sprintf('<%s> has two rest props, "%s" and "%s". A class component has at most one.', ComponentName::short($class), $rest->getName(), $p->getName()));
            }

            if (!self::acceptsArray($p->getType())) {
                throw new PropError(\sprintf('The rest prop "%s" of <%s> gets a hash of props, so its type must accept an array.', $p->getName(), ComponentName::short($class)));
            }

            $rest = $p;
        }

        $this->rests[$class] = $rest;

        return $this->props[$class] = $out;
    }

    /**
     * The rest prop of a class component (`#[Prop(rest: true)]`): it gets the props that no
     * other prop takes, as a hash.
     *
     * @param class-string $class
     */
    public function restProp(string $class): ?\ReflectionProperty
    {
        if (!\array_key_exists($class, $this->rests)) {
            $this->props($class);
        }

        return $this->rests[$class];
    }

    /**
     * The #[State] properties of a class component: the state that the browser keeps.
     *
     * @param class-string $class
     *
     * @return list<\ReflectionProperty>
     */
    public function stateProperties(string $class): array
    {
        return $this->states[$class] ??= array_values(array_filter(
            (new \ReflectionClass($class))->getProperties(),
            static fn (\ReflectionProperty $p) => !$p->isStatic() && [] !== $p->getAttributes(State::class),
        ));
    }

    /**
     * The #[State] properties of a class component whose type is a StateValue, with the class.
     *
     * @param class-string $class
     *
     * @return list<array{\ReflectionProperty, class-string<StateValue>}>
     */
    public function stateValues(string $class): array
    {
        if (isset($this->stateValues[$class])) {
            return $this->stateValues[$class];
        }

        $out = [];
        foreach ($this->stateProperties($class) as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), StateValue::class)) {
                $out[] = [$p, $type->getName()];
            }
        }

        return $this->stateValues[$class] = $out;
    }

    /**
     * The #[State] property of the type Validation of a class component: it turns on the live
     * validation (see Phem\Validation). Null when the component has none.
     *
     * @param class-string $class
     *
     * @throws \LogicException when the component has more than one
     */
    public function validationProperty(string $class): ?\ReflectionProperty
    {
        if (\array_key_exists($class, $this->validations)) {
            return $this->validations[$class];
        }

        $found = array_values(array_filter($this->stateValues($class), static fn (array $v): bool => Validation::class === $v[1]));
        if (\count($found) > 1) {
            throw new \LogicException(\sprintf('<%s> has more than one #[State] property of the type Phem\Validation ("%s"). A component has at most one: it validates all its #[State] properties.', ComponentName::short($class), implode('", "', array_map(static fn (array $v): string => $v[0]->getName(), $found))));
        }

        return $this->validations[$class] = $found[0][0] ?? null;
    }

    /**
     * Whether a name is a component that is not a page: it has #[Component] and no #[Page]. An
     * abstract class is not a component: its children declare it.
     */
    public function isComponent(string $name): bool
    {
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }

        $reflection = self::reflect($name);
        if (null === $reflection || ($reflection instanceof \ReflectionClass && $reflection->isAbstract())) {
            return $this->components[$name] = false;
        }

        return $this->components[$name] = [] !== $reflection->getAttributes(Component::class) && [] === $reflection->getAttributes(Page::class);
    }

    /** The component that a component replaces (`#[Component(replaces: ...)]`), or null. */
    public function replaces(string $name): ?string
    {
        if (\array_key_exists($name, $this->replaces)) {
            return $this->replaces[$name];
        }

        $attribute = self::reflect($name)?->getAttributes(Component::class)[0] ?? null;
        $replaces = $attribute?->newInstance()->replaces;

        return $this->replaces[$name] = null !== $replaces ? ltrim($replaces, '\\') : null;
    }

    /**
     * Whether a page or a component gets the streams of other requests (see
     * Phem\Attribute\Broadcast): it has #[Broadcast], or a class component has #[Broadcast] on a
     * handler method.
     *
     * @throws \LogicException when a handler method has #[Broadcast], and its class is not a component
     */
    public function broadcasts(string $name): bool
    {
        if (isset($this->broadcasts[$name])) {
            return $this->broadcasts[$name];
        }

        $reflection = self::reflect($name);
        if (null === $reflection) {
            return $this->broadcasts[$name] = false;
        }

        // Before the attribute of the class: a page with #[Broadcast] has no handler that refreshes it.
        $handlers = $reflection instanceof \ReflectionClass ? $this->broadcastHandlers($name) : [];
        if ([] !== $handlers && !$this->isComponent($name)) {
            throw new \LogicException(\sprintf('The method "%s::%s()" has #[Broadcast], but "%s" is not a component: only a handler of a class component refreshes its component. Put #[Broadcast] on the page itself.', $name, $handlers[0], $name));
        }

        return $this->broadcasts[$name] = [] !== $handlers || [] !== $reflection->getAttributes(Broadcast::class);
    }

    /**
     * The props of a component with #[BroadcastKey], in their order: its properties, or the
     * parameters of a function component.
     *
     * @return list<string>
     */
    public function broadcastKeys(string $name): array
    {
        if (isset($this->broadcastKeys[$name])) {
            return $this->broadcastKeys[$name];
        }

        $reflection = self::reflect($name);
        $members = match (true) {
            $reflection instanceof \ReflectionClass => $reflection->getProperties(),
            $reflection instanceof \ReflectionFunction => $reflection->getParameters(),
            default => [],
        };

        $keys = [];
        foreach ($members as $member) {
            if ([] !== $member->getAttributes(BroadcastKey::class)) {
                $keys[] = $member->getName();
            }
        }

        return $this->broadcastKeys[$name] = $keys;
    }

    /**
     * The handler methods of a class with #[Broadcast]: only they refresh the component (see
     * Phem\Handler\BroadcastMiddleware).
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public function broadcastHandlers(string $class): array
    {
        if (isset($this->broadcastHandlers[$class])) {
            return $this->broadcastHandlers[$class];
        }

        $methods = [];
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if ([] !== $method->getAttributes(Broadcast::class)) {
                $methods[] = $method->getName();
            }
        }

        return $this->broadcastHandlers[$class] = $methods;
    }

    /**
     * Whether mount() of a class component runs each time that the component renders: the class
     * has a mount() and no #[State] property. The browser keeps no state for it, so mount() reads
     * its data again (for example from a repository).
     */
    public function mountsEachRender(string $class): bool
    {
        return class_exists($class) && method_exists($class, 'mount') && [] === $this->stateProperties($class);
    }

    /**
     * The #[Url] properties of a class component, by query key (see Phem\Attribute\Url).
     *
     * @param class-string $class
     *
     * @return array<string, array{0: \ReflectionProperty, 1: Url}> key => the property and its attribute
     *
     * @throws \LogicException when a #[Url] property is not #[State], has a type that a query string cannot hold, has an `except` value that does not fit its type, or has the key of another property
     */
    public function urlProperties(string $class): array
    {
        if (isset($this->urls[$class])) {
            return $this->urls[$class];
        }

        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $p) {
            $attribute = $p->getAttributes(Url::class)[0] ?? null;
            if (null === $attribute) {
                continue;
            }

            $url = $attribute->newInstance();
            $key = $url->as ?? $p->getName();
            $short = ComponentName::short($class);

            if ($p->isStatic() || [] === $p->getAttributes(State::class)) {
                throw new \LogicException(\sprintf('The #[Url] property "%s" of <%s> must be a #[State] property.', $p->getName(), $short));
            }

            // PHP changes "." and " " in a query key to "_", and "[" starts a list.
            if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $key)) {
                throw new \LogicException(\sprintf('The query key "%s" of the #[Url] property "%s" of <%s> can have only letters, digits, "_" and "-".', $key, $p->getName(), $short));
            }

            if (!self::fitsQuery($p->getType())) {
                throw new \LogicException(\sprintf('The #[Url] property "%s" of <%s> must be a string, an int, a float, a bool, a backed enum, or an array of them.', $p->getName(), $short));
            }

            if (Url::DEFAULT !== $url->except && !(UrlQuery::isMap($url->except) ? self::acceptsArray($p->getType()) : UrlQuery::convert($p->getType(), UrlQuery::encode($url->except, $key)))) {
                throw new \LogicException(\sprintf('The "except" value of the #[Url] property "%s" of <%s> does not fit the type of the property.', $p->getName(), $short));
            }

            if (isset($out[$key])) {
                throw new \LogicException(\sprintf('The #[Url] properties "%s" and "%s" of <%s> have the same query key "%s".', $out[$key][0]->getName(), $p->getName(), $short, $key));
            }

            $out[$key] = [$p, $url];
        }

        return $this->urls[$class] = $out;
    }

    /**
     * The #[Path] public properties of a class page, or the parameters of a function page: they
     * get the route values with their names.
     *
     * @return array<string, ?\ReflectionType> name => type
     */
    public function pathProps(string $page): array
    {
        if (isset($this->paths[$page])) {
            return $this->paths[$page];
        }

        $out = [];
        if (class_exists($page)) {
            foreach ($this->props($page) as $name => $p) {
                if ([] !== $p->getAttributes(Path::class)) {
                    $out[$name] = $p->getType();
                }
            }
        } elseif (\function_exists($page)) {
            foreach ((new \ReflectionFunction($page))->getParameters() as $p) {
                if (!$p->isVariadic()) {
                    $out[$p->getName()] = $p->getType();
                }
            }
        }

        return $this->paths[$page] = $out;
    }

    /** Whether a query string can hold a value of this type: no type, a scalar, a backed enum, an array, or a union of them. */
    private static function fitsQuery(?\ReflectionType $type): bool
    {
        return match (true) {
            null === $type => true,
            $type instanceof \ReflectionNamedType => \in_array($type->getName(), ['string', 'int', 'float', 'bool', 'true', 'false', 'null', 'array', 'mixed'], true) || is_subclass_of($type->getName(), \BackedEnum::class),
            $type instanceof \ReflectionUnionType => array_all($type->getTypes(), self::fitsQuery(...)),
            default => false,
        };
    }

    /** Whether a property of this type can get an array: no type, `array`, `iterable`, `mixed`, or a union with one. */
    private static function acceptsArray(?\ReflectionType $type): bool
    {
        return match (true) {
            null === $type => true,
            $type instanceof \ReflectionNamedType => \in_array($type->getName(), ['array', 'iterable', 'mixed'], true),
            $type instanceof \ReflectionUnionType => array_any($type->getTypes(), self::acceptsArray(...)),
            default => false,
        };
    }

    /** The reflection of a class or a function by its name, or null for another name (a template). */
    private static function reflect(string $name): \ReflectionClass|\ReflectionFunction|null
    {
        return match (true) {
            class_exists($name) => new \ReflectionClass($name),
            \function_exists($name) => new \ReflectionFunction($name),
            default => null,
        };
    }
}
