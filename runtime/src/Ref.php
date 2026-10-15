<?php

declare(strict_types=1);

namespace Rephp;

/**
 * The value of `useRef()`: a box that keeps its value between renders.
 */
final class Ref
{
    public function __construct(public mixed $current = null)
    {
    }
}
