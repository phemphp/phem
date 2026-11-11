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
 * The `theme_script()` Twig function: an inline script for the `<head>` of the document, which
 * sets the theme before the first paint, so a dark page does not flash white:
 *
 *     <head>
 *         {{ theme_script() }}
 *         {{ importmap('app') }}
 *     </head>
 *
 * The script reads the choice of the user (`light`, `dark` or `system`) from the `phem-theme` key
 * in `localStorage`, and gives `<html>` the theme: `data-theme="light"` or `data-theme="dark"`,
 * and the same `color-scheme`. With `system`, or with no choice, the theme follows the
 * `prefers-color-scheme` of the system, also when it changes. A choice in another tab changes the
 * theme too. The `phem-theme` controller (assets/dist/phem_theme_controller.js) stores the choice.
 *
 * `default` is the theme with no choice. The script gives it to `<html>` (`data-theme-default`),
 * for the controller. `nonce` is the nonce of a Content Security Policy.
 */
final class ThemeScript
{
    /** The choices of the user, as in assets/dist/phem_theme.js. */
    public const THEMES = ['light', 'dark', 'system'];
    /** The key of the choice in `localStorage`: `new PhemStorage("theme")` in phem_theme.js. */
    public const KEY = 'phem-theme';

    // %1$s is the default theme, %2$s the list of the themes, %3$s the key.
    private const SCRIPT = <<<'JS'
        (()=>{const d=%1$s,r=document.documentElement,m=matchMedia("(prefers-color-scheme: dark)"),a=()=>{let p=null;try{p=localStorage.getItem(%3$s)}catch{}if(!%2$s.includes(p))p=d;const t=p==="system"?(m.matches?"dark":"light"):p;r.dataset.theme=t;r.style.colorScheme=t};r.dataset.themeDefault=d;a();m.addEventListener("change",a);addEventListener("storage",e=>{if(e.key===null||e.key===%3$s)a()})})()
        JS;

    public static function render(string $default = 'system', ?string $nonce = null): Markup
    {
        if (!\in_array($default, self::THEMES, true)) {
            throw new \InvalidArgumentException(\sprintf('The default theme must be "light", "dark" or "system", "%s" given.', $default));
        }

        $attributes = null === $nonce ? '' : \sprintf(' nonce="%s"', htmlspecialchars($nonce, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $script = \sprintf(
            self::SCRIPT,
            json_encode($default, \JSON_THROW_ON_ERROR),
            json_encode(self::THEMES, \JSON_THROW_ON_ERROR),
            json_encode(self::KEY, \JSON_THROW_ON_ERROR),
        );

        return new Markup(\sprintf('<script%s>%s</script>', $attributes, $script), 'UTF-8');
    }
}
