<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Attribute\Path;
use Rephp\Attribute\State;
use Rephp\Html;

/**
 * Binds props to the parameters of a function component or to the public properties of a
 * class component, with defaults and clear errors. It also reads and writes the #[State]
 * properties. It keeps the reflection data for the life of the service.
 *
 * A #[Path] prop with an entity class type gets the entity of its route value, from the
 * EntityFinder (Doctrine ORM, when DoctrineBundle is installed).
 */
final class PropBinder
{
    /** @var array<string, array<string, array{optional: bool, default: mixed, hasDefault: bool, nullable: bool, variadic: bool}>> */
    private array $functions = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $properties = [];

    /** @var array<string, list<\ReflectionProperty>> */
    private array $states = [];

    /** @var array<string, array<string, ?\ReflectionType>> */
    private array $paths = [];

    public function __construct(private readonly ?EntityFinder $entities = null)
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
        $params = $this->params($fqcn);
        $variadic = \in_array(true, array_column($params, 'variadic'), true);
        $args = [];
        foreach ($props + $slots as $name => $value) {
            if (!isset($params[$name]) && !$variadic) {
                throw new PropError(\sprintf('<%s> has no prop "%s". Its props are: %s.', self::short($fqcn), $name, implode(', ', array_keys($params)) ?: 'none'));
            }
            $args[$name] = $value;
        }
        foreach ($params as $name => $p) {
            if (!$p['variadic'] && !\array_key_exists($name, $args) && !$p['optional']) {
                if (!$p['nullable']) {
                    throw new PropError(\sprintf('<%s> needs the prop "%s".', self::short($fqcn), $name));
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
     *
     * @return array<string, mixed>
     */
    public function templateContext(string $fqcn, array $props): array
    {
        $params = $this->params($fqcn);
        $vars = [];
        $rest = [];
        foreach ($this->arguments($fqcn, $props) as $name => $value) {
            if (isset($params[$name]) && !$params[$name]['variadic']) {
                $vars[$name] = $value;
            } else {
                $rest[$name] = $value;
            }
        }
        foreach ($params as $name => $p) {
            if ($p['variadic']) {
                $vars[$name] = $rest;
            } elseif (!\array_key_exists($name, $vars)) {
                $vars[$name] = $p['default'];
            }
        }

        return $vars;
    }

    /**
     * Writes the props (and slots) into a class component. A missing prop gets its default.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     */
    public function assign(object $component, array $props, array $slots = []): void
    {
        $class = $component::class;
        $all = $props + $slots;
        $properties = $this->properties($class);
        foreach ($all as $name => $_) {
            if (!isset($properties[$name])) {
                throw new PropError(\sprintf('<%s> has no prop "%s". Its props are: %s.', self::short($class), $name, implode(', ', array_keys($properties)) ?: 'none'));
            }
        }
        foreach ($properties as $name => $p) {
            if ($p->isReadOnly() && $p->isInitialized($component)) {
                continue;
            }
            if (\array_key_exists($name, $all)) {
                $p->setValue($component, $all[$name]);
            } elseif ($p->hasDefaultValue()) {
                $p->setValue($component, $p->getDefaultValue());
            } elseif ($p->getType()?->allowsNull() ?? true) {
                $p->setValue($component, null);
            } elseif (!$p->isInitialized($component)) {
                throw new PropError(\sprintf('<%s> needs the prop "%s".', self::short($class), $name));
            }
        }
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
                if (!isset($this->properties($page)[$p->getName()])) {
                    throw new PropError(\sprintf('The #[Path] property "%s" of <%s> must be a prop: public, not static, not promoted, and not #[State].', $p->getName(), self::short($page)));
                }
                $out[$p->getName()] = $p->getType();
            }
        } else {
            $this->params($page);
            foreach ((new \ReflectionFunction($page))->getParameters() as $p) {
                if ([] === $p->getAttributes(Path::class)) {
                    continue;
                }
                if ($p->isVariadic()) {
                    throw new PropError(\sprintf('The #[Path] parameter "%s" of <%s> cannot be variadic.', $p->getName(), self::short($page)));
                }
                $out[$p->getName()] = $p->getType();
            }
        }

        return $this->paths[$page] = $out;
    }

    /**
     * The props of a page from its route values: the values of the #[Path] properties or
     * parameters. A string goes to an `int`, `float` or `bool` type as a value of that type.
     * An entity type gets the entity: an identifier goes to `find()`, and criteria
     * (field => value, from `{slug:post}`) go to `findOneBy()`.
     *
     * @param array<string, mixed> $values the route values
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when a value does not match its type (`abc` for `int $id`), or no entity matches it
     */
    public function pathValues(string $page, array $values): array
    {
        $out = [];
        foreach ($this->pathProps($page) as $name => $type) {
            if (\array_key_exists($name, $values)) {
                $out[$name] = $this->pathValue($page, $name, $type, $values[$name]);
            }
        }

        return $out;
    }

    /**
     * The #[State] properties of a class component: the state that the browser keeps.
     *
     * @return array<string, mixed>
     */
    public function exportState(object $component): array
    {
        $out = [];
        foreach ($this->stateProperties($component::class) as $p) {
            if ($p->isInitialized($component)) {
                $out[$p->getName()] = $p->getValue($component);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $state the state that the browser sent back */
    public function restoreState(object $component, array $state): void
    {
        foreach ($this->stateProperties($component::class) as $p) {
            if (\array_key_exists($p->getName(), $state)) {
                $p->setValue($component, $state[$p->getName()]);
            }
        }
    }

    public static function short(string $fqcn): string
    {
        return substr(strrchr('\\'.$fqcn, '\\'), 1);
    }

    private function pathValue(string $page, string $name, ?\ReflectionType $type, mixed $value): mixed
    {
        if (!$type instanceof \ReflectionNamedType || \is_object($value)) {
            return $value;
        }
        if (!$type->isBuiltin()) {
            return $this->entity($page, $name, $type, $value);
        }
        if (!\is_string($value)) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE),
            'bool' => filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE),
            default => $value,
        } ?? throw new \UnexpectedValueException(\sprintf('The route value "%s" of <%s> is not a valid %s: "%s".', $name, self::short($page), $type->getName(), $value));
    }

    private function entity(string $page, string $name, \ReflectionNamedType $type, mixed $value): ?object
    {
        $class = $type->getName();
        if (null === $this->entities || !$this->entities->supports($class)) {
            throw new PropError(\sprintf('The #[Path] prop "%s" of <%s> has the type %s, which is not a Doctrine entity. A #[Path] prop is an int, float, bool, string, or a Doctrine entity (install doctrine/orm and doctrine/doctrine-bundle).', $name, self::short($page), $class));
        }
        $entity = null !== $value ? $this->entities->find($class, $value) : null;
        if (null === $entity && !$type->allowsNull()) {
            throw new \UnexpectedValueException(\sprintf('No %s matches the route value "%s" of <%s>.', self::short($class), $name, self::short($page)));
        }

        return $entity;
    }

    /** @return array<string, array{optional: bool, default: mixed, hasDefault: bool, nullable: bool, variadic: bool}> */
    private function params(string $fqcn): array
    {
        if (isset($this->functions[$fqcn])) {
            return $this->functions[$fqcn];
        }
        if (!\function_exists($fqcn)) {
            throw new PropError(\sprintf('Component function "%s" does not exist. Run `rhpc build` again.', $fqcn));
        }
        $out = [];
        foreach ((new \ReflectionFunction($fqcn))->getParameters() as $p) {
            $hasDefault = $p->isDefaultValueAvailable();
            $out[$p->getName()] = [
                'optional' => $p->isOptional(),
                'default' => $hasDefault ? $p->getDefaultValue() : null,
                'hasDefault' => $hasDefault,
                'nullable' => $p->allowsNull(),
                'variadic' => $p->isVariadic(),
            ];
        }

        return $this->functions[$fqcn] = $out;
    }

    /** @return array<string, \ReflectionProperty> the props of a class component */
    private function properties(string $class): array
    {
        if (isset($this->properties[$class])) {
            return $this->properties[$class];
        }
        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic() && !$p->isPromoted() && [] === $p->getAttributes(State::class)) {
                $out[$p->getName()] = $p;
            }
        }

        return $this->properties[$class] = $out;
    }

    /** @return list<\ReflectionProperty> */
    private function stateProperties(string $class): array
    {
        return $this->states[$class] ??= array_values(array_filter(
            (new \ReflectionClass($class))->getProperties(),
            static fn (\ReflectionProperty $p) => !$p->isStatic() && [] !== $p->getAttributes(State::class),
        ));
    }
}
