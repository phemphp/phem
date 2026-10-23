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
use Phem\Element;
use Phem\Html;
use Phem\HtmlList;
use Phem\LazyHtml;
use Phem\Manifest\Manifest;
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
            return new LazyHtml(fn (): string => $this->twig->render(Manifest::templateName($value->template), $this->items($value->vars)));
        }

        if (\is_array($value)) {
            $value = $this->items($value, $markup);

            return $markup ? (HtmlList::of($value) ?? $value) : $value;
        }

        return $value;
    }

    /**
     * The items of an array, with their markup resolved. Only an Element or an array can contain
     * markup. An array whose items do not change is the same array: the data of a page (for
     * example its rows) is not copied for each element that gets it.
     *
     * @param array<array-key, mixed> $items
     *
     * @param-out bool $markup whether an item is markup after the resolve
     *
     * @return array<array-key, mixed>
     */
    private function items(array $items, ?bool &$markup = null): array
    {
        $markup = false;
        foreach ($items as $key => $item) {
            if ($item instanceof Element || \is_array($item)) {
                $resolved = $this->resolve($item);
                // The same array is identical with no comparison of its items.
                if ($resolved !== $item) {
                    $items[$key] = $item = $resolved;
                }
            }

            $markup = $markup || $item instanceof Html;
        }

        return $items;
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
