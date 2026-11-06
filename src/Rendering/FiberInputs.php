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
 * What a parent gives a fiber when it renders: the props and the template of the slots. The
 * Twig context of the caller is not in it: the slots render again each time that their owner
 * renders (see UpdateScope).
 *
 * @internal
 */
final class FiberInputs
{
    /** @param array<string, mixed> $props */
    public function __construct(
        public readonly array $props,
        public readonly ?Template $slots,
    ) {
    }
}
