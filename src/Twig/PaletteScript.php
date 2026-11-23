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

use Twig\Markup;

/**
 * The `palette_script()` Twig function: an inline script for the `<head>` that gives `<html>` the
 * palette of the user before the first paint (`data-palette`), from `localStorage`, else the
 * default. It gives `<html>` the names (`data-palettes`) and the default (`data-palette-default`)
 * for the `phem-palette` controller, and follows a choice in another tab.
 *
 *     {{ palette_script(['base', 'shadcn']) }}
 */
final class PaletteScript
{
    /** The key of the choice in `localStorage`: `new PhemStorage("palette")` in phem_palette.js. */
    public const KEY = 'phem-palette';

    /** A name goes in `data-palettes` (a list with spaces) and in CSS attribute selectors. */
    private const NAME = '/^[a-z0-9][a-z0-9-]*$/';

    // %1$s is the list of the palettes, %2$s the default, %3$s the key.
    private const SCRIPT = <<<'JS'
        (()=>{const l=%1$s,d=%2$s,r=document.documentElement,a=()=>{let p=null;try{p=localStorage.getItem(%3$s)}catch{}r.dataset.palette=l.includes(p)?p:d};r.dataset.palettes=l.join(" ");r.dataset.paletteDefault=d;a();addEventListener("storage",e=>{if(e.key===null||e.key===%3$s)a()})})()
        JS;

    /**
     * @param list<string> $palettes the names of the palettes; the first one is the default
     */
    public static function render(array $palettes, ?string $default = null, ?string $nonce = null): Markup
    {
        if ([] === $palettes || !array_is_list($palettes)) {
            throw new \InvalidArgumentException('The palettes must be a list of names.');
        }

        foreach ($palettes as $palette) {
            if (!\is_string($palette) || 1 !== preg_match(self::NAME, $palette)) {
                throw new \InvalidArgumentException(\sprintf('A palette name has only lowercase letters, digits and dashes, "%s" given.', \is_string($palette) ? $palette : get_debug_type($palette)));
            }
        }

        $default ??= $palettes[0];

        if (!\in_array($default, $palettes, true)) {
            throw new \InvalidArgumentException(\sprintf('The default palette must be one of "%s", "%s" given.', implode('", "', $palettes), $default));
        }

        $attributes = null === $nonce ? '' : \sprintf(' nonce="%s"', htmlspecialchars($nonce, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $script = \sprintf(
            self::SCRIPT,
            json_encode($palettes, \JSON_THROW_ON_ERROR),
            json_encode($default, \JSON_THROW_ON_ERROR),
            json_encode(self::KEY, \JSON_THROW_ON_ERROR),
        );

        return new Markup(\sprintf('<script%s>%s</script>', $attributes, $script), 'UTF-8');
    }
}
