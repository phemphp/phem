<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Attribute\State;
use Rephp\Html;

/**
 * Binds props to the parameters of a function component or to the public properties of a
 * class component, with defaults and clear errors. It also reads and writes the #[State]
 * properties. It keeps the reflection data for the life of the service.
 */
final class PropBinder
{
    /** @var array<string, array<string, array{optional: bool, default: mixed, hasDefault: bool, nullable: bool, variadic: bool}>> */
    private array $functions = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $properties = [];

    /** @var array<string, list<\ReflectionProperty>> */
    private array $states = [];

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
     * The context of a template component: the props, then the parameter defaults.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public function templateContext(string $fqcn, array $props): array
    {
        $vars = $this->arguments($fqcn, $props);
        foreach ($this->params($fqcn) as $name => $p) {
            if (!\array_key_exists($name, $vars) && !$p['variadic']) {
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
