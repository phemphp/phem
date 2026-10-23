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

namespace Phem\Dom;

use Phem\Bound;
use Twig\Markup;

/**
 * Renders the HTML attributes of the compiled markup, as React does: boolean attributes, a
 * `class` list, a `style` object, and the `key` of an element.
 */
final class Attributes
{
    /** React prop names → HTML attribute names. */
    public const ALIASES = ['className' => 'class', 'htmlFor' => 'for'];

    /**
     * The attributes that take the text "true" or "false" (lowercase), as in React: a boolean
     * value is not a boolean attribute for them. Also `aria-*` and `data-*`.
     */
    private const BOOLEANISH = ['autoreverse', 'contenteditable', 'draggable', 'externalresourcesrequired', 'focusable', 'preservealpha', 'spellcheck', 'value'];

    /**
     * The attributes with a URL that the browser opens or sends a form to, as in React: a
     * `javascript:` URL in them runs as a script (see attr()).
     */
    private const URLS = ['action', 'formaction', 'href', 'src', 'xlink:href'];

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
        return new Markup(null === $key ? '' : 'data-phem-key="'.self::e((string) $key).'"', 'UTF-8');
    }

    /** Adds `data-phem-key` to the first element of an HTML fragment. */
    public static function keyed(string $html, string $key): string
    {
        if (1 !== preg_match('/^\s*<[a-zA-Z][\w:-]*/', $html, $m)) {
            return $html;
        }

        return $m[0].' data-phem-key="'.self::e($key).'"'.substr($html, \strlen($m[0]));
    }

    /**
     * Whether an attribute name is valid HTML: no space, quote, `<`, `>`, `/`, `=` or control
     * character, which could end the name and start another attribute.
     */
    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[^\s\x00-\x1F\x7F"\'<>\/=]+$/', $name);
    }

    /**
     * An attribute with an invalid name (see isValidName()) is not rendered, as in React. A URL
     * attribute (`href`, `src`, `action`, `formAction`, `xlink:href`) with a `javascript:` URL is
     * not rendered either: the URL can come from data (the website of a user), and a click would
     * run it as a script.
     */
    public static function attr(string $name, mixed $value): Markup
    {
        if (\is_bool($value) && self::isBooleanish($name)) {
            $value = $value ? 'true' : 'false';
        }

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

        $value = (string) $value;
        if (self::isScriptUrl($name, $value)) {
            return new Markup('', 'UTF-8');
        }

        return new Markup(\sprintf('%s="%s"', self::e($name), self::e($value)), 'UTF-8');
    }

    /**
     * `phem:debounce={{ 300 }}` → `data-phem-debounce="300"`, and the same for `phem:throttle`: the
     * browser waits before it sends the events of the element. `phem:debounce={{ 'blur' }}` sends
     * them on blur only. Null or false renders nothing.
     */
    public static function timing(string $kind, mixed $value): Markup
    {
        if (null === $value || false === $value) {
            return new Markup('', 'UTF-8');
        }

        if (!\in_array($kind, ['debounce', 'throttle'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown timing "phem:%s": use "phem:debounce" or "phem:throttle".', $kind));
        }

        if (!(\is_int($value) && $value >= 0) && !('debounce' === $kind && 'blur' === $value)) {
            throw new \InvalidArgumentException(\sprintf('"phem:%s" takes a number of milliseconds (0 or more)%s, "%s" given.', $kind, 'debounce' === $kind ? " or 'blur'" : '', get_debug_type($value)));
        }

        return new Markup(\sprintf('data-phem-%s="%s"', $kind, $value), 'UTF-8');
    }

    /**
     * The text of a bound value in a form field (`phem:bind`): a string, a number, the value of a
     * backed enum, or '' for null. A bool is '1' or ''.
     */
    public static function bindValue(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            $value instanceof Bound => self::bindValue($value->value),
            $value instanceof \BackedEnum => (string) $value->value,
            \is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(\sprintf('A bound field (`phem:bind`) shows a string, a number or a backed enum, "%s" given.', get_debug_type($value))),
        };
    }

    /**
     * `selected` for an `<option>` of a bound `<select>` (`phem:bind`): when its value is the
     * bound value, or is in the bound list of a `<select multiple>`.
     */
    public static function selected(mixed $bound, mixed $option): Markup
    {
        $option = self::bindValue($option);
        if ($bound instanceof Bound) {
            $bound = $bound->value;
        }

        $selected = \is_array($bound)
            ? \in_array($option, array_map(self::bindValue(...), $bound), true)
            : null !== $bound && self::bindValue($bound) === $option;

        return new Markup($selected ? 'selected' : '', 'UTF-8');
    }

    private static function isScriptUrl(string $name, string $value): bool
    {
        return \in_array(strtolower($name), self::URLS, true) && 'javascript' === UrlScheme::of($value);
    }

    private static function isBooleanish(string $name): bool
    {
        $name = strtolower($name);

        return str_starts_with($name, 'aria-') || str_starts_with($name, 'data-') || \in_array($name, self::BOOLEANISH, true);
    }

    public static function style(mixed $style): Markup
    {
        if (null === $style || false === $style || [] === $style) {
            return new Markup('', 'UTF-8');
        }

        if (\is_array($style)) {
            $rules = [];
            foreach ($style as $prop => $value) {
                $prop = (string) $prop;

                // A style object can come from data: a name or a value that adds another
                // declaration (`red; position: fixed`) is not rendered.
                if (null === $value || false === $value || 1 !== preg_match('/^-?-?[a-zA-Z][\w-]*$/', $prop) || !self::isCssValue((string) $value)) {
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

    /**
     * Whether a CSS value is one value: no `;`, `{` or `}` outside of quotes and parentheses
     * (`url("data:image/png;base64,...")` is valid), and the quotes and parentheses are closed.
     */
    private static function isCssValue(string $value): bool
    {
        $quote = null;
        $depth = 0;
        for ($i = 0, $n = \strlen($value); $i < $n; ++$i) {
            $c = $value[$i];
            if (null !== $quote) {
                if ('\\' === $c) {
                    ++$i;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ('"' === $c || "'" === $c) {
                $quote = $c;
            } elseif ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                if (--$depth < 0) {
                    return false;
                }
            } elseif (0 === $depth && (';' === $c || '{' === $c || '}' === $c)) {
                return false;
            }
        }

        return null === $quote && 0 === $depth;
    }
}
