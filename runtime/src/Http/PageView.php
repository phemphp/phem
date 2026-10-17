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

namespace Mamu\Http;

use Mamu\Dom\Attributes;
use Mamu\Dom\DomEvents;
use Twig\Markup;

/**
 * A rendered page, for the document of the page (see Mamu\Document), or for a Twig template
 * (the `page` variable).
 *
 *     {{ mamu_root(page) }}                    {# <div data-controller="mamu" …>…</div> #}
 *     {{ mamu_root(page, {class: 'app'}) }}
 *     <body {{ mamu_root_attributes(page) }}>{{ page.body }}</body>
 *
 * The root element of the page needs the attributes of the `mamu` Stimulus controller (the
 * state, its signature, and the DOM events), and it contains the body.
 */
final class PageView
{
    /** The attributes that the application cannot change. */
    private const OWN = ['data-mamu-state-value' => true, 'data-mamu-signature-value' => true];

    /**
     * @param string $component the page component
     * @param Markup $body      the HTML of the page
     */
    public function __construct(
        public readonly string $component,
        public readonly Markup $body,
        public readonly string $state,
        public readonly string $signature,
    ) {
    }

    /**
     * The attributes of the root element. The `data-controller` and `data-action` values of the
     * application come after the values of Mamu. The `id` is `mamu-root` (null removes it).
     *
     * @param array<string, mixed> $attributes more attributes, as in a component (`class` can be a list)
     */
    public function attributes(array $attributes = []): Markup
    {
        $own = [
            'id' => \array_key_exists('id', $attributes) ? $attributes['id'] : 'mamu-root',
            'data-controller' => self::join('mamu', $attributes['data-controller'] ?? null),
            'data-action' => self::join(DomEvents::stimulusActions(), $attributes['data-action'] ?? null),
            'data-mamu-state-value' => $this->state,
            'data-mamu-signature-value' => $this->signature,
        ];
        $html = [];
        foreach ($own + array_diff_key($attributes, self::OWN) as $name => $value) {
            $attr = (string) Attributes::attr((string) $name, $value);
            if ('' !== $attr) {
                $html[] = $attr;
            }
        }

        return new Markup(implode(' ', $html), 'UTF-8');
    }

    /**
     * The root element with the body of the page.
     *
     * @param array<string, mixed> $attributes see attributes()
     */
    public function root(array $attributes = [], string $tag = 'div'): Markup
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/i', $tag)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name for the root of a page.', $tag));
        }

        return new Markup(\sprintf('<%s %s>%s</%1$s>', $tag, $this->attributes($attributes), $this->body), 'UTF-8');
    }

    private static function join(string $own, mixed $app): string
    {
        return trim($own.' '.(\is_string($app) ? $app : ''));
    }
}
