<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem;

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
