<?php

declare(strict_types=1);

namespace Mamu\Dom;

use Twig\Markup;

/**
 * Renders the HTML attributes of the compiled markup, as React does: boolean attributes, a
 * `class` list, a `style` object, and the `key` of an element.
 */
final class Attributes
{
    /** React prop names → HTML attribute names. */
    public const ALIASES = ['className' => 'class', 'htmlFor' => 'for'];

    /** Unitless CSS properties (camelCase), as in React. */
    private const UNITLESS = [
        'animationIterationCount', 'aspectRatio', 'columnCount', 'columns', 'flex', 'flexGrow', 'flexShrink',
        'fontWeight', 'gridColumn', 'gridRow', 'lineClamp', 'lineHeight', 'opacity', 'order', 'orphans',
        'scale', 'tabSize', 'widows', 'zIndex', 'zoom',
    ];

    public static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The HTML attribute name of a prop name (`className` → `class`). */
    public static function name(string $name): string
    {
        return self::ALIASES[$name] ?? $name;
    }

    public static function key(mixed $key): Markup
    {
        return new Markup(null === $key ? '' : 'data-mamu-key="'.self::e((string) $key).'"', 'UTF-8');
    }

    /** Adds `data-mamu-key` to the first element of an HTML fragment. */
    public static function keyed(string $html, string $key): string
    {
        $attr = ' data-mamu-key="'.htmlspecialchars($key, \ENT_QUOTES, 'UTF-8').'"';

        return preg_replace('/^(\s*<[a-zA-Z][\w:-]*)/', '$1'.$attr, $html, 1) ?? $html;
    }

    /**
     * Whether an attribute name is valid HTML: no space, quote, `<`, `>`, `/`, `=` or control
     * character, which could end the name and start another attribute.
     */
    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[^\s\x00-\x1F\x7F"\'<>\/=]+$/', $name);
    }

    /** An attribute with an invalid name (see isValidName()) is not rendered, as in React. */
    public static function attr(string $name, mixed $value): Markup
    {
        if (null === $value || false === $value || !self::isValidName($name)) {
            return new Markup('', 'UTF-8');
        }
        if (true === $value) {
            return new Markup(self::e($name), 'UTF-8');
        }
        if ('class' === $name && \is_array($value)) {
            $value = implode(' ', array_filter(array_map(strval(...), $value), static fn ($v) => '' !== $v));
        } elseif (\is_array($value)) {
            $value = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return new Markup(\sprintf('%s="%s"', self::e($name), self::e((string) $value)), 'UTF-8');
    }

    public static function style(mixed $style): Markup
    {
        if (null === $style || false === $style || [] === $style) {
            return new Markup('', 'UTF-8');
        }
        if (\is_array($style)) {
            $rules = [];
            foreach ($style as $prop => $value) {
                if (null === $value || false === $value) {
                    continue;
                }
                $css = str_starts_with($prop, '--') ? $prop : strtolower(preg_replace('/[A-Z]/', '-$0', $prop) ?? $prop);
                if ((\is_int($value) || \is_float($value)) && 0 != $value && !\in_array($prop, self::UNITLESS, true)) {
                    $value .= 'px';
                }
                $rules[] = $css.': '.$value;
            }
            $style = implode('; ', $rules);
        }

        return new Markup('style="'.self::e((string) $style).'"', 'UTF-8');
    }
}
