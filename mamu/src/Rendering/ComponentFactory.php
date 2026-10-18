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

namespace Mamu\Rendering;

/**
 * Makes the object of a class component: a new instance for each fiber.
 */
interface ComponentFactory
{
    public function create(string $fqcn): object;
}
