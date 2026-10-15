<?php

declare(strict_types=1);

namespace Rephp\Attribute;

/**
 * Marks a class as a component. The class must have a `render()` method; its public
 * properties are the props.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Component
{
}
