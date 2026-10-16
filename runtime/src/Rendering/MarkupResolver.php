<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Element;
use Rephp\Html;
use Rephp\HtmlList;
use Rephp\Manifest\Manifest;
use Twig\Environment;

/**
 * Renders markup values: an Element becomes Html, and a list of markup becomes an HtmlList.
 * Other values do not change.
 */
final class MarkupResolver
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function resolve(mixed $value): mixed
    {
        if ($value instanceof Element) {
            return new Html($this->twig->render(Manifest::templateName($value->template), array_map($this->resolve(...), $value->vars)));
        }
        if (\is_array($value)) {
            $value = array_map($this->resolve(...), $value);

            return HtmlList::accepts($value) ? new HtmlList($value) : $value;
        }

        return $value;
    }
}
