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

use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\State;
use Phem\Attribute\Url;
use Phem\Binding\ComponentName;
use Phem\Binding\PropError;

/**
 * The reflection data of the components: the parameters of a function component, and the
 * #[Prop] and #[State] properties of a class component. It keeps the data for the life of the
 * service.
 */
final class ComponentMetadata
{
    /** @var array<string, array<string, Parameter>> */
    private array $parameters = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $props = [];

    /** @var array<string, ?\ReflectionProperty> */
    private array $rests = [];

    /** @var array<string, list<\ReflectionProperty>> */
    private array $states = [];

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
     * The #[Url] properties of a class component, by query key (see Phem\Attribute\Url).
     *
     * @param class-string $class
     *
     * @return array<string, array{0: \ReflectionProperty, 1: Url}> key => the property and its attribute
     *
     * @throws \LogicException when a #[Url] property is not #[State], has a type that a query string cannot hold, or has the key of another property
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
            if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                throw new \LogicException(\sprintf('The query key "%s" of the #[Url] property "%s" of <%s> can have only letters, digits, "_" and "-".', $key, $p->getName(), $short));
            }
            if (!self::fitsQuery($p->getType())) {
                throw new \LogicException(\sprintf('The #[Url] property "%s" of <%s> must be a string, an int, a float, a bool, a backed enum, or an array of them.', $p->getName(), $short));
            }
            if (isset($out[$key])) {
                throw new \LogicException(\sprintf('The #[Url] properties "%s" and "%s" of <%s> have the same query key "%s".', $out[$key][0]->getName(), $p->getName(), $short, $key));
            }
            $out[$key] = [$p, $url];
        }

        return $this->urls[$class] = $out;
    }

    /**
     * The #[Path] public properties of a page: they get the route values. A function component
     * has none.
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
}
