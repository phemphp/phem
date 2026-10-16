<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Dom\Attributes;
use Rephp\Element;
use Rephp\Html;
use Rephp\HtmlList;
use Rephp\Manifest\Manifest;
use Twig\Environment;
use Twig\Markup;

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

    /**
     * The HTML of the output of a component. As in React, a string is text: it is escaped.
     * Only markup (Html, or other Twig Markup) prints as HTML.
     */
    public function html(mixed $value): string
    {
        return self::stringify($this->resolve($value));
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value instanceof Markup => (string) $value,
            null === $value => '',
            \is_array($value) => implode('', array_map(self::stringify(...), $value)),
            default => Attributes::e((string) $value),
        };
    }
}
