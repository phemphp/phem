<?php

declare(strict_types=1);

namespace Rephp;

use Rephp\Runtime\Renderer;

/**
 * Declares a state variable: `[$count, $setCount] = useState(0);`
 * The setter takes a value or an updater: `$setCount(fn($c) => $c + 1)`.
 *
 * @return array{0: mixed, 1: \Closure}
 */
function useState(mixed $initial = null): array
{
    return Renderer::useState($initial);
}

/**
 * State with a reducer: `[$state, $dispatch] = useReducer($reducer, $initial);`
 *
 * @return array{0: mixed, 1: \Closure}
 */
function useReducer(callable $reducer, mixed $initial, ?callable $init = null): array
{
    return Renderer::useReducer($reducer, $initial, $init);
}

/** A value that persists between renders without a re-render: `$ref->current`. */
function useRef(mixed $initial = null): Ref
{
    return Renderer::useRef($initial);
}

/** @param list<mixed>|null $deps */
function useMemo(callable $compute, ?array $deps = null): mixed
{
    return Renderer::useMemo($compute, $deps);
}

/** @param list<mixed>|null $deps */
function useCallback(\Closure $callback, ?array $deps = null): \Closure
{
    return Renderer::useMemo(static fn () => $callback, $deps);
}

/** A unique, stable id (for `id` / `htmlFor` pairs). */
function useId(): string
{
    return Renderer::useId();
}

function useContext(Context $context): mixed
{
    return Renderer::useContext($context);
}

/**
 * The built-in `<Provider context="App\ThemeContext" value="dark">` component.
 */
function Provider(Context|string $context, mixed $value = null, ?Html $children = null): Html
{
    $ctx = \is_string($context) ? \constant(ltrim($context, '\\')) : $context;
    if (!$ctx instanceof Context) {
        throw new \InvalidArgumentException(\sprintf('<Provider context="%s"> is not a Rephp\Context.', \is_string($context) ? $context : get_debug_type($context)));
    }
    Renderer::provide($ctx, $value);

    return $children ?? Html::empty();
}
