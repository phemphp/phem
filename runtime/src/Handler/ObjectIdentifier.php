<?php

declare(strict_types=1);

namespace Mamu\Handler;

/**
 * Identifies an object that a handler captures (`fn () => $this->remove($scientist)`), for
 * example the identifier of a Doctrine entity. HandlerFingerprint uses it: a handler id
 * stays the same in the next request when the object is the same row.
 */
interface ObjectIdentifier
{
    /** @return array<string, mixed>|null the identifier values, or null for an unknown object */
    public function identify(object $value): ?array;
}
