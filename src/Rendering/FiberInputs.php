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

namespace Phem\Rendering;

use Twig\Template;

/**
 * What a parent gives a fiber when it renders: the props, the template of the slots, and the
 * Twig context of the caller (the slots render with it).
 *
 * @internal
 */
final class FiberInputs
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    public function __construct(
        public readonly array $props,
        public readonly ?Template $slots,
        public readonly array $caller,
    ) {
    }
}
