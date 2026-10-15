<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Runtime;
use Rephp\Runtime\Renderer;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `rhp_*` functions that compiled templates use.
 */
final class RephpExtension extends AbstractExtension
{
    /** Unitless CSS properties (camelCase), as in React. */
    private const UNITLESS = [
        'animationIterationCount', 'aspectRatio', 'columnCount', 'columns', 'flex', 'flexGrow', 'flexShrink',
        'fontWeight', 'gridColumn', 'gridRow', 'lineClamp', 'lineHeight', 'opacity', 'order', 'orphans',
        'scale', 'tabSize', 'widows', 'zIndex', 'zoom',
    ];

    /** React event names → DOM event names. */
    private const EVENTS = ['doubleclick' => 'dblclick', 'focus' => 'focusin', 'blur' => 'focusout'];

    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function getTokenParsers(): array
    {
        return [new ComponentTokenParser($this->runtime)];
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('rhp_on', $this->on(...), $safe),
            new TwigFunction('rhp_key', self::key(...), $safe),
            new TwigFunction('rhp_attr', self::attr(...), $safe),
            new TwigFunction('rhp_style', self::style(...), $safe),
            new TwigFunction('rhp_attrs', $this->attrs(...), $safe),
        ];
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** `onClick={{ f }}` → `data-rh-on-click="<handler id>"`. */
    public function on(string $event, mixed $handler): Markup
    {
        if (null === $handler || false === $handler) {
            return new Markup('', 'UTF-8');
        }
        if (!\is_callable($handler)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" handler must be a function, "%s" given. Pass the function, do not call it.', $event, get_debug_type($handler)));
        }
        $id = Renderer::current()?->registerHandler(\Closure::fromCallable($handler)) ?? 'static';
        $event = self::EVENTS[$event] ?? $event;

        return new Markup(\sprintf('data-rh-on-%s="%s"', $event, self::e($id)), 'UTF-8');
    }

    public static function key(mixed $key): Markup
    {
        return new Markup(null === $key ? '' : 'data-rh-key="'.self::e((string) $key).'"', 'UTF-8');
    }

    public static function attr(string $name, mixed $value): Markup
    {
        if (null === $value || false === $value) {
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
                $css = str_starts_with($prop, '--') ? $prop : strtolower(preg_replace('/[A-Z]/', '-$0', $prop));
                if ((\is_int($value) || \is_float($value)) && 0 != $value && !\in_array($prop, self::UNITLESS, true)) {
                    $value .= 'px';
                }
                $rules[] = $css.': '.$value;
            }
            $style = implode('; ', $rules);
        }

        return new Markup('style="'.self::e((string) $style).'"', 'UTF-8');
    }

    /** `{{ ...props }}` on an HTML element. */
    public function attrs(mixed $attrs): Markup
    {
        $out = [];
        foreach ((array) $attrs as $name => $value) {
            $name = (string) $name;
            $out[] = (string) match (true) {
                'key' === $name => self::key($value),
                'style' === $name => self::style($value),
                1 === preg_match('/^on[A-Z]/', $name) => $this->on(strtolower(substr($name, 2)), $value),
                default => self::attr(['className' => 'class', 'htmlFor' => 'for'][$name] ?? $name, $value),
            };
        }

        return new Markup(implode(' ', array_filter($out)), 'UTF-8');
    }
}
