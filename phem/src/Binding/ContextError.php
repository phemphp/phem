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

namespace Phem\Binding;

/**
 * A component needs a context (see Phem\Attribute\Context), but no component above it provides
 * one.
 */
final class ContextError extends \LogicException
{
}
