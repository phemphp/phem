<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Dom\Attributes;
use Rephp\Manifest\Manifest;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `rhp_*` functions that compiled templates use.
 *
 * The functions that need the current render tree (`rhp_on`, `rhp_attrs`) are in RephpRuntime.
 * The attribute functions render with Rephp\Dom\Attributes.
 */
final class RephpExtension extends AbstractExtension
{
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
            new TwigFunction('rhp_key', Attributes::key(...), $safe),
            new TwigFunction('rhp_attr', Attributes::attr(...), $safe),
            new TwigFunction('rhp_style', Attributes::style(...), $safe),
            new TwigFunction('rhp_attrs', [RephpRuntime::class, 'attrs'], $safe),
        ];
    }
}
