<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
