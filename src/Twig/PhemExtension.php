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

use Phem\Binding\SpreadProps;
use Phem\ClassList;
use Phem\Dom\Attributes;
use Phem\Dom\DynamicTag;
use Phem\Handler\HandlerCall;
use Phem\Handler\MemberHandler;
use Phem\Manifest\Manifest;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `{% component %}` tag and the `phem_*` functions that compiled templates use.
 *
 * The functions that need the current render tree or a service (`phem_on`, `phem_loading`, `phem_progress`, `phem_bind`, `phem_class`, `phem_attrs`) are in PhemRuntime.
 * The attribute functions render with Phem\Dom\Attributes, and the tag of `<Dynamic>` with Phem\Dom\DynamicTag. `classes()` gives a mergeable class list (Phem\ClassList).
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
            new TwigFunction('phem_loading', [PhemRuntime::class, 'loading'], $safe),
            new TwigFunction('phem_progress', [PhemRuntime::class, 'progress'], $safe),
            new TwigFunction('phem_bind', [PhemRuntime::class, 'bind'], $safe),
            new TwigFunction('phem_bound', [PhemRuntime::class, 'bound']),
            new TwigFunction('phem_bind_value', Attributes::bindValue(...)),
            new TwigFunction('phem_selected', Attributes::selected(...), $safe),
            new TwigFunction('phem_key', Attributes::key(...), $safe),
            new TwigFunction('phem_attr', Attributes::attr(...), $safe),
            new TwigFunction('phem_style', Attributes::style(...), $safe),
            new TwigFunction('phem_timing', Attributes::timing(...), $safe),
            new TwigFunction('phem_tag', DynamicTag::of(...)),
            new TwigFunction('phem_end_tag', DynamicTag::close(...), $safe),
            new TwigFunction('phem_class', [PhemRuntime::class, 'class'], $safe),
            new TwigFunction('phem_attrs', [PhemRuntime::class, 'attrs'], $safe),
            new TwigFunction('phem_props', SpreadProps::merge(...)),
            new TwigFunction('classes', ClassList::of(...)),
            new TwigFunction('phem_method', MemberHandler::of(...)),
            new TwigFunction('phem_call', HandlerCall::bind(...)),
        ];
    }
}
