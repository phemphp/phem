<?php

declare(strict_types=1);

namespace Rephp\Runtime;

use Rephp\Attribute\State;
use Rephp\Html;

/**
 * Binds props to the parameters of a function component (or the properties of a class
 * component), with defaults and clear errors. The reflection data is cached.
 *
 * @internal
 */
final class Props
{
    /** @var array<string, array<string, array{optional: bool, default: mixed, hasDefault: bool, nullable: bool, variadic: bool}>> */
    private static array $functions = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private static array $classes = [];

    /** @return array<string, array{optional: bool, default: mixed, hasDefault: bool, nullable: bool, variadic: bool}> */
    public static function params(string $fqcn): array
    {
        if (isset(self::$functions[$fqcn])) {
            return self::$functions[$fqcn];
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

        return self::$functions[$fqcn] = $out;
    }

    /**
     * Named arguments for a function component.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     *
     * @return array<string, mixed>
     */
    public static function arguments(string $fqcn, array $props, array $slots): array
    {
        $params = self::params($fqcn);
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
     * The context of a template component: props, then the parameter defaults.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public static function templateContext(string $fqcn, array $props): array
    {
        $vars = self::arguments($fqcn, $props, []);
        foreach (self::params($fqcn) as $name => $p) {
            if (!\array_key_exists($name, $vars) && !$p['variadic']) {
                $vars[$name] = $p['default'];
            }
        }

        return $vars;
    }

    /** @return array<string, \ReflectionProperty> the props of a class component */
    public static function properties(string $class): array
    {
        if (isset(self::$classes[$class])) {
            return self::$classes[$class];
        }
        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic() && [] === $p->getAttributes(State::class)) {
                $out[$p->getName()] = $p;
            }
        }

        return self::$classes[$class] = $out;
    }

    /**
     * Writes the props (and slots) into a class component. Missing props get their defaults.
     *
     * @param array<string, mixed> $props
     * @param array<string, Html>  $slots
     */
    public static function assign(object $instance, array $props, array $slots): void
    {
        $class = $instance::class;
        $all = $props + $slots;
        $properties = self::properties($class);
        foreach ($all as $name => $_) {
            if (!isset($properties[$name])) {
                throw new PropError(\sprintf('<%s> has no prop "%s". Its props are: %s.', self::short($class), $name, implode(', ', array_keys($properties)) ?: 'none'));
            }
        }
        foreach ($properties as $name => $p) {
            if ($p->isReadOnly() && $p->isInitialized($instance)) {
                continue;
            }
            if (\array_key_exists($name, $all)) {
                $p->setValue($instance, $all[$name]);
            } elseif ($p->hasDefaultValue()) {
                $p->setValue($instance, $p->getDefaultValue());
            } elseif ($p->getType()?->allowsNull() ?? true) {
                $p->setValue($instance, null);
            } elseif (!$p->isInitialized($instance)) {
                throw new PropError(\sprintf('<%s> needs the prop "%s".', self::short($class), $name));
            }
        }
    }

    public static function short(string $fqcn): string
    {
        return substr(strrchr('\\'.$fqcn, '\\'), 1);
    }
}
