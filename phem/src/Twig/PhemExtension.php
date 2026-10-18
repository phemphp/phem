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

namespace Phem\Twig;

use Phem\Dom\Attributes;
use Phem\Manifest\Manifest;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `phem_*` functions that compiled templates use.
 *
 * The functions that need the current render tree (`phem_on`, `phem_attrs`) are in PhemRuntime.
 * The attribute functions render with Phem\Dom\Attributes.
 */
final class PhemExtension extends AbstractExtension
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
            new TwigFunction('phem_on', [PhemRuntime::class, 'on'], $safe),
            new TwigFunction('phem_key', Attributes::key(...), $safe),
            new TwigFunction('phem_attr', Attributes::attr(...), $safe),
            new TwigFunction('phem_style', Attributes::style(...), $safe),
            new TwigFunction('phem_attrs', [PhemRuntime::class, 'attrs'], $safe),
        ];
    }
}
