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

use Phem\Dom\Attributes;
use Phem\Html;

/**
 * The validation error of a bound field (see FieldErrors). `phem:error={{ email }}` renders the
 * element with this id and this message. The bound field gets the same id in its
 * `aria-describedby`.
 */
final readonly class FieldError
{
    public function __construct(
        public string $id,
        public string $message,
    ) {
    }

    /** The escaped message, for the `children` prop of a component. */
    public function html(): Html
    {
        return new Html(Attributes::e($this->message));
    }
}
