<?php

declare(strict_types=1);

namespace Mamu\Attribute;

/**
 * Marks a property of a class component as state. A change in an event handler re-renders
 * the component. The value must be JSON-serializable.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class State
{
}
