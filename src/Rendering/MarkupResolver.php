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
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;
use Twig\Markup;
use Twig\TemplateWrapper;

/**
 * Renders markup values: an Element becomes Html that renders on first use, and a list of
 * markup becomes an HtmlList.
 * Other values do not change.
 *
 * With the render pass of the Renderer, the markup renders one time in each pass (see
 * Phem\LazyHtml): a component that renders alone in a later pass of an event can print the
 * markup values of its parent again (a slot), and their components render again in it.
 */
final class MarkupResolver implements ResetInterface
{
    /**
     * The templates of the markup literals of this request, by name. Environment::render() makes
     * the cache key and the class of the template again for each call: a list of 5000 rows has
     * 5000 calls for one template.
     *
     * @var array<string, TemplateWrapper>
     */
    private array $templates = [];

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param (\Closure(): int)|null $pass the render pass now, or null to render one time only */
    public function resolve(mixed $value, ?\Closure $pass = null): mixed
    {
        if ($value instanceof Element) {
            // The markup renders when a template prints it. Thus the markup of a branch that does
            // not show (`{{ on ? yes : no }}`) mounts no component and adds no handler.
            return new LazyHtml(fn (): string => $this->template($value->template)->render($this->items($value->vars, $pass)), $pass);
        }

        if (\is_array($value)) {
            $value = $this->items($value, $pass, $markup);

            return $markup ? (HtmlList::of($value, $pass) ?? $value) : $value;
        }

        return $value;
    }

    /**
     * The items of an array, with their markup resolved. Only an Element or an array can contain
     * markup. An array whose items do not change is the same array: the data of a page (for
     * example its rows) is not copied for each element that gets it.
     *
     * @param array<array-key, mixed> $items
     * @param (\Closure(): int)|null  $pass
     *
     * @param-out bool $markup whether an item is markup after the resolve
     *
     * @return array<array-key, mixed>
     */
    private function items(array $items, ?\Closure $pass, ?bool &$markup = null): array
    {
        $markup = false;
        foreach ($items as $key => $item) {
            if ($item instanceof Element || \is_array($item)) {
                $resolved = $this->resolve($item, $pass);
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
     *
     * @param (\Closure(): int)|null $pass the render pass now, or null to render one time only
     */
    public function html(mixed $value, ?\Closure $pass = null): string
    {
        return self::stringify($this->resolve($value, $pass));
    }

    /** A new request can have new templates (after a phemc build in debug mode). */
    public function reset(): void
    {
        $this->templates = [];
    }

    private function template(string $template): TemplateWrapper
    {
        return $this->templates[$template] ??= $this->twig->load(Manifest::templateName($template));
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
