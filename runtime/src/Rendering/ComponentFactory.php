<?php

declare(strict_types=1);

namespace Mamu\Rendering;

/**
 * Makes the object of a class component: a new instance for each fiber.
 */
interface ComponentFactory
{
    public function create(string $fqcn): object;
}
