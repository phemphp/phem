<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Rendering;

use Mamu\Dom\Attributes;
use Mamu\Element;
use Mamu\HtmlList;
use Mamu\LazyHtml;
use Mamu\Manifest\Manifest;
use Twig\Environment;
use Twig\Markup;

/**
 * Renders markup values: an Element becomes Html that renders on first use, and a list of
 * markup becomes an HtmlList.
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
            // The markup renders when a template prints it. Thus the markup of a branch that does
            // not show (`{{ on ? yes : no }}`) mounts no component and adds no handler.
            return new LazyHtml(fn (): string => $this->twig->render(Manifest::templateName($value->template), array_map($this->resolve(...), $value->vars)));
        }
        if (\is_array($value)) {
            $value = array_map($this->resolve(...), $value);

            return HtmlList::of($value) ?? $value;
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
