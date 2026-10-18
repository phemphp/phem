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

namespace Mamu\Twig;

use Mamu\Dom\Attributes;
use Mamu\Manifest\Manifest;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `mamu_*` functions that compiled templates use.
 *
 * The functions that need the current render tree (`mamu_on`, `mamu_attrs`) are in MamuRuntime.
 * The attribute functions render with Mamu\Dom\Attributes.
 */
final class MamuExtension extends AbstractExtension
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
            new TwigFunction('mamu_on', [MamuRuntime::class, 'on'], $safe),
            new TwigFunction('mamu_key', Attributes::key(...), $safe),
            new TwigFunction('mamu_attr', Attributes::attr(...), $safe),
            new TwigFunction('mamu_style', Attributes::style(...), $safe),
            new TwigFunction('mamu_attrs', [MamuRuntime::class, 'attrs'], $safe),
        ];
    }
}
