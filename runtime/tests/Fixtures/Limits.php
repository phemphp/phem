<?php

declare(strict_types=1);

namespace Rephp\Tests\Fixtures;

/** A service that the fixture Counter component gets in its constructor. */
final class Limits
{
    public int $max = 3;
}
