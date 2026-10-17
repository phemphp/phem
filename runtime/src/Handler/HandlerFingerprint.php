<?php

declare(strict_types=1);

namespace Mamu\Handler;

/**
 * The fingerprint of an event handler: its function (class, method or closure, with its line)
 * and the values that it captures (`fn () => $this->remove($id)`).
 *
 * A handler id contains it, so the id comes from what the handler does, not from its position
 * in the render. When the data changes between the render and the click (another user removes
 * a row), the other rows keep their ids, and the id of a removed row is not found (409): the
 * click never runs another handler.
 */
final class HandlerFingerprint
{
    private const MAX_DEPTH = 3;

    public function __construct(private readonly ?ObjectIdentifier $objects = null)
    {
    }

    public function of(\Closure $handler): string
    {
        $function = new \ReflectionFunction($handler);

        return substr(hash('xxh128', serialize([
            $function->getClosureScopeClass()?->getName(),
            $function->getName(),
            $this->normalize($function->getClosureUsedVariables(), 0),
        ])), 0, 12);
    }

    /** A value with only scalars and arrays: an object becomes its class and its identity. */
    private function normalize(mixed $value, int $depth): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \BackedEnum => [$value::class, $value->value],
            $value instanceof \UnitEnum => [$value::class, $value->name],
            \is_array($value) => $depth < self::MAX_DEPTH ? array_map(fn (mixed $v): mixed => $this->normalize($v, $depth + 1), $value) : 'array',
            $value instanceof \Closure => 'Closure',
            \is_object($value) => [$value::class, $this->objects?->identify($value) ?? ($depth < self::MAX_DEPTH ? $this->normalize(get_object_vars($value), $depth + 1) : null)],
            default => get_debug_type($value),
        };
    }
}
