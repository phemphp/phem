<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Manifest;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `rhp_*` functions that compiled templates use.
 *
 * The functions that need the current render tree (`rhp_on`, `rhp_attrs`) are in RephpRuntime.
 */
final class RephpExtension extends AbstractExtension
{
    /** Unitless CSS properties (camelCase), as in React. */
    private const UNITLESS = [
        'animationIterationCount', 'aspectRatio', 'columnCount', 'columns', 'flex', 'flexGrow', 'flexShrink',
        'fontWeight', 'gridColumn', 'gridRow', 'lineClamp', 'lineHeight', 'opacity', 'order', 'orphans',
        'scale', 'tabSize', 'widows', 'zIndex', 'zoom',
    ];

    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function getTokenParsers(): array
    {
        return [new ComponentTokenParser($this->manifest)];
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('rhp_on', [RephpRuntime::class, 'on'], $safe),
            new TwigFunction('rhp_key', self::key(...), $safe),
            new TwigFunction('rhp_attr', self::attr(...), $safe),
            new TwigFunction('rhp_style', self::style(...), $safe),
            new TwigFunction('rhp_attrs', [RephpRuntime::class, 'attrs'], $safe),
        ];
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
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
}
