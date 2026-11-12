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

namespace Phem\Tests\Runtime;

use Phem\Manifest\Manifest;
use Phem\Twig\PhemExtension;
use Phem\Twig\ThemeScript;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ThemeScriptTest extends TestCase
{
    public function testRendersAnInlineScriptWithTheSystemThemeByDefault(): void
    {
        $html = (string) ThemeScript::render();

        self::assertStringStartsWith('<script>(()=>{const d="system",', $html);
        self::assertStringEndsWith('})()</script>', $html);
        self::assertStringContainsString('localStorage.getItem("phem-theme")', $html);
        self::assertStringContainsString('if(!["light","dark","system"].includes(p))p=d', $html);
        self::assertStringContainsString('r.dataset.themeDefault=d', $html);
        self::assertStringContainsString('matchMedia("(prefers-color-scheme: dark)")', $html);
    }

    public function testRendersTheDefaultTheme(): void
    {
        self::assertStringStartsWith('<script>(()=>{const d="dark",', (string) ThemeScript::render('dark'));
    }

    public function testRendersTheNonce(): void
    {
        self::assertStringStartsWith('<script nonce="a&quot;b">', (string) ThemeScript::render(nonce: 'a"b'));
    }

    public function testRejectsAnUnknownTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The default theme must be "light", "dark" or "system", "blue" given.');

        ThemeScript::render('blue');
    }

    public function testHasTheThemesAndTheKeyOfTheController(): void
    {
        $module = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/dist/phem_theme.js');
        $storage = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/dist/phem_storage.js');

        self::assertSame(1, preg_match('/^export const THEMES = (\[.*\])$/m', $module, $themes));
        self::assertSame(ThemeScript::THEMES, json_decode($themes[1], true, flags: \JSON_THROW_ON_ERROR));

        // PhemStorage adds the `phem-` prefix to the key of the controller.
        self::assertStringContainsString('new PhemStorage("'.substr(ThemeScript::KEY, \strlen('phem-')).'")', $module);
        self::assertStringContainsString('this.#key = `phem-${key}`', $storage);
    }

    public function testIsATwigFunctionThatIsNotEscaped(): void
    {
        $twig = new Environment(new ArrayLoader(['head' => "<head>{{ theme_script(default: 'light') }}</head>"]));
        $twig->addExtension(new PhemExtension(new Manifest(sys_get_temp_dir())));

        self::assertStringStartsWith('<head><script>(()=>{const d="light",', $twig->render('head'));
    }
}
