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
 * Marks a class as a component. The class must have a `render()` method; its public
 * properties are the props.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Component
{
}
