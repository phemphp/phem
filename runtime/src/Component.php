<?php

declare(strict_types=1);

namespace Rephp;

/**
 * Optional helper for class components.
 */
trait Component
{
    /**
     * Sets several state properties at once.
     *
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        foreach ($state as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
