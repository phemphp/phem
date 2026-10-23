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

namespace Phem\Tests\Fixtures;

use Phem\Attribute\Provide;

/** A component that provides an enum (see Phem\Rendering\ContextResolver). */
final class ThemeProvider
{
    public function __construct(
        #[Provide]
        public Theme $theme = Theme::Light,
    ) {
    }
}
