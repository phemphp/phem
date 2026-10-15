<?php

declare(strict_types=1);

namespace Rephp;

use Twig\Markup;

/**
 * Rendered markup. Twig prints it without escaping.
 * A markup literal in PHP (`$content = <AdminPanel />;`) is an Html value.
 */
class Html extends Markup
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
