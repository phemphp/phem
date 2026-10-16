<?php

declare(strict_types=1);

namespace Rephp\Metadata;

use Rephp\Attribute\Path;
use Rephp\Attribute\State;
use Rephp\Binding\ComponentName;
use Rephp\Binding\PropError;

/**
 * The reflection data of the components: the parameters of a function component, and the
 * props and #[State] properties of a class component. It keeps the data for the life of the
 * service.
 */
final class ComponentMetadata
{
    /** @var array<string, array<string, Parameter>> */
    private array $parameters = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $props = [];

    /** @var array<string, list<\ReflectionProperty>> */
    private array $states = [];

    /** @var array<string, array<string, ?\ReflectionType>> */
    private array $paths = [];

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
            throw new PropError(\sprintf('Component function "%s" does not exist. Run `rhpc build` again.', $function));
        }
        $out = [];
        foreach ((new \ReflectionFunction($function))->getParameters() as $p) {
            $out[$p->getName()] = Parameter::fromReflection($p);
        }

        return $this->parameters[$function] = $out;
    }

    /**
     * The props of a class component: its public properties that are not static, not
     * promoted, and not #[State].
     *
     * @return array<string, \ReflectionProperty>
     */
    public function props(string $class): array
    {
        if (isset($this->props[$class])) {
            return $this->props[$class];
        }
        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic() && !$p->isPromoted() && [] === $p->getAttributes(State::class)) {
                $out[$p->getName()] = $p;
            }
        }

        return $this->props[$class] = $out;
    }

    /**
     * The #[State] properties of a class component: the state that the browser keeps.
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
     * The #[Path] public properties of a class page, or the #[Path] parameters of a function
     * page: they get the route values.
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
            foreach ((new \ReflectionClass($page))->getProperties() as $p) {
                if ([] === $p->getAttributes(Path::class)) {
                    continue;
                }
                if (!isset($this->props($page)[$p->getName()])) {
                    throw new PropError(\sprintf('The #[Path] property "%s" of <%s> must be a prop: public, not static, not promoted, and not #[State].', $p->getName(), ComponentName::short($page)));
                }
                $out[$p->getName()] = $p->getType();
            }
        } else {
            $this->parameters($page);
            foreach ((new \ReflectionFunction($page))->getParameters() as $p) {
                if ([] === $p->getAttributes(Path::class)) {
                    continue;
                }
                if ($p->isVariadic()) {
                    throw new PropError(\sprintf('The #[Path] parameter "%s" of <%s> cannot be variadic.', $p->getName(), ComponentName::short($page)));
                }
                $out[$p->getName()] = $p->getType();
            }
        }

        return $this->paths[$page] = $out;
    }
}
