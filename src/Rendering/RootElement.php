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
use Phem\Dom\DomEvents;

/**
 * The root element of a page (see Phem\Root): the attributes of the `phem` Stimulus controller
 * around the content.
 *
 *     <div id="phem-root" data-controller="phem" data-action="…" data-phem-state-value="…" data-phem-signature-value="…" data-phem-query-value="…">…</div>
 *
 * @internal
 */
final class RootElement
{
    /** The attributes that the application cannot change. */
    private const OWN = ['data-phem-state-value' => true, 'data-phem-signature-value' => true, 'data-phem-query-value' => true, 'data-phem-stream-value' => true];

    /**
     * The `data-controller` and `data-action` values of the application come after the values of
     * Phem. The `id` is `phem-root` (null removes it).
     *
     * @param array<string, mixed> $props the props of `<Root>`: `tag` and the attributes, as in a component (`className`, a `class` list)
     * @param string               $state the state attributes (see state()), or the token that PageRenderer replaces with them
     */
    public static function render(array $props, string $state, string $content): string
    {
        $tag = $props['tag'] ?? 'div';
        if (!\is_string($tag) || 1 !== preg_match('/^[a-z][a-z0-9-]*$/i', $tag)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name for the root of a page.', \is_string($tag) ? $tag : get_debug_type($tag)));
        }

        unset($props['tag']);
        $attributes = [];
        foreach ($props as $name => $value) {
            $attributes[Attributes::name((string) $name)] = $value;
        }

        $html = [];
        foreach ([
            'id' => \array_key_exists('id', $attributes) ? $attributes['id'] : 'phem-root',
            'data-controller' => self::join('phem', $attributes['data-controller'] ?? null),
            'data-action' => self::join(DomEvents::stimulusActions(), $attributes['data-action'] ?? null),
        ] as $name => $value) {
            $html[] = (string) Attributes::attr($name, $value);
        }

        $html[] = $state;

        foreach (array_diff_key($attributes, ['id' => true, 'data-controller' => true, 'data-action' => true, 'key' => true] + self::OWN) as $name => $value) {
            $html[] = (string) match (true) {
                'style' === $name => Attributes::style($value),
                // No event handler: the root element dispatches the events of the page.
                0 === stripos($name, 'on') => '',
                default => Attributes::attr($name, $value),
            };
        }

        return \sprintf('<%s %s>%s</%1$s>', $tag, implode(' ', array_filter($html, static fn (string $a): bool => '' !== $a)), $content);
    }

    /**
     * The state attributes of the root element.
     *
     * @param array{values: array<string, string|array<array-key, mixed>|null>, push: list<string>}|null $query the query of the #[Url] properties (see Phem\State\UrlQuery::of()), or null for none
     */
    public static function state(string $state, string $signature, ?array $query = null): string
    {
        $html = Attributes::attr('data-phem-state-value', $state).' '.Attributes::attr('data-phem-signature-value', $signature);
        if (null !== $query && [] !== $query['values']) {
            $html .= ' '.Attributes::attr('data-phem-query-value', json_encode(['values' => (object) $query['values'], 'push' => $query['push']], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return $html;
    }

    private static function join(string $own, mixed $app): string
    {
        return trim($own.' '.(\is_string($app) ? $app : ''));
    }
}
