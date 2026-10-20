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

use Twig\Markup;

/**
 * Rendered markup. Twig prints it without escaping.
 * A markup literal in PHP (`$content = <AdminPanel />;`) is an Element. It becomes Html when it
 * passes to a component as a prop or a slot.
 */
class Html extends Markup implements Node
{
    public function __construct(string $html)
    {
        parent::__construct($html, 'UTF-8');
    }

    public static function empty(): self
    {
        return new self('');
    }
}
