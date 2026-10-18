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
use Mamu\Dom\DomEvents;

/**
 * The root element of a page (see Mamu\Root): the attributes of the `mamu` Stimulus controller
 * around the content.
 *
 *     <div id="mamu-root" data-controller="mamu" data-action="…" data-mamu-state-value="…" data-mamu-signature-value="…">…</div>
 *
 * @internal
 */
final class RootElement
{
    /** The attributes that the application cannot change. */
    private const OWN = ['data-mamu-state-value' => true, 'data-mamu-signature-value' => true];

    /**
     * The `data-controller` and `data-action` values of the application come after the values of
     * Mamu. The `id` is `mamu-root` (null removes it).
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
            'id' => \array_key_exists('id', $attributes) ? $attributes['id'] : 'mamu-root',
            'data-controller' => self::join('mamu', $attributes['data-controller'] ?? null),
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

    /** The state attributes of the root element. */
    public static function state(string $state, string $signature): string
    {
        return Attributes::attr('data-mamu-state-value', $state).' '.Attributes::attr('data-mamu-signature-value', $signature);
    }

    private static function join(string $own, mixed $app): string
    {
        return trim($own.' '.(\is_string($app) ? $app : ''));
    }
}
