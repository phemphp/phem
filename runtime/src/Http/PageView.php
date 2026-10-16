<?php

declare(strict_types=1);

namespace Rephp\Http;

use Rephp\Dom\Attributes;
use Rephp\Dom\DomEvents;
use Twig\Markup;

/**
 * A rendered page, for the page template of the application (the `page` variable).
 *
 *     <title>{{ page.title ?? 'My app' }}</title>
 *     …
 *     {{ rephp_root(page) }}                    {# <div data-controller="rhp" …>…</div> #}
 *     {{ rephp_root(page, {class: 'app'}) }}
 *     <body {{ rephp_root_attributes(page) }}>{{ page.body }}</body>
 *
 * The root element of the page needs the attributes of the `rhp` Stimulus controller (the
 * state, its signature, and the DOM events), and it contains the body.
 */
final class PageView
{
    /** The attributes that the application cannot change. */
    private const OWN = ['data-rhp-state-value' => true, 'data-rhp-signature-value' => true];

    /**
     * @param string      $component the page component
     * @param Markup|null $title     the `<title>` of the page (HTML), or null without one
     * @param Markup      $body      the HTML of the page
     */
    public function __construct(
        public readonly string $component,
        public readonly ?Markup $title,
        public readonly Markup $body,
        public readonly string $state,
        public readonly string $signature,
    ) {
    }

    /**
     * The attributes of the root element. The `data-controller` and `data-action` values of the
     * application come after the values of rephp. The `id` is `rh-root` (null removes it).
     *
     * @param array<string, mixed> $attributes more attributes, as in a component (`class` can be a list)
     */
    public function attributes(array $attributes = []): Markup
    {
        $own = [
            'id' => \array_key_exists('id', $attributes) ? $attributes['id'] : 'rh-root',
            'data-controller' => self::join('rhp', $attributes['data-controller'] ?? null),
            'data-action' => self::join(DomEvents::stimulusActions(), $attributes['data-action'] ?? null),
            'data-rhp-state-value' => $this->state,
            'data-rhp-signature-value' => $this->signature,
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
