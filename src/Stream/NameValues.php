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

use Phem\Handler\ObjectIdentifier;

/**
 * The values of a name of a topic or a key (see Topics and BroadcastKeys) as strings, with their
 * keys in order: the page and the publisher get the same name for `42` and `"42"`.
 *
 * An enum is its value. With an ObjectIdentifier, an entity is its identifier (not its class: a
 * Doctrine proxy and its entity give the same name).
 */
final class NameValues
{
    public function __construct(
        private readonly ?ObjectIdentifier $objects = null,
    ) {
    }

    /**
     * @param array<array-key, mixed>              $values
     * @param \Closure(string, mixed): \Throwable $invalid the exception for a value with no string: its key and the value
     *
     * @return array<array-key, mixed>
     */
    public function normalize(array $values, \Closure $invalid): array
    {
        ksort($values);
        foreach ($values as $key => $value) {
            $values[$key] = $this->value($value, (string) $key, $invalid);
        }

        return $values;
    }

    /** @param \Closure(string, mixed): \Throwable $invalid */
    private function value(mixed $value, string $key, \Closure $invalid): mixed
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            \is_scalar($value) => (string) $value,
            \is_array($value) => $this->normalize($value, $invalid),
            // Before Stringable: an entity is its identifier, not its title.
            \is_object($value) && null !== ($id = $this->objects?->identify($value)) => $this->normalize($id, $invalid),
            $value instanceof \Stringable => (string) $value,
            default => throw $invalid($key, $value),
        };
    }
}
