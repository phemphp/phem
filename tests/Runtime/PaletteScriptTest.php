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
use Phem\Twig\PaletteScript;
use Phem\Twig\PhemExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class PaletteScriptTest extends TestCase
{
    public function testRendersAnInlineScriptWithTheFirstPaletteAsTheDefault(): void
    {
        $html = (string) PaletteScript::render(['base', 'shadcn']);

        self::assertStringStartsWith('<script>(()=>{const l=["base","shadcn"],d="base",', $html);
        self::assertStringEndsWith('})()</script>', $html);
        self::assertStringContainsString('localStorage.getItem("phem-palette")', $html);
        self::assertStringContainsString('r.dataset.palette=l.includes(p)?p:d', $html);
        self::assertStringContainsString('r.dataset.palettes=l.join(" ");r.dataset.paletteDefault=d', $html);
    }

    public function testRendersTheDefaultPalette(): void
    {
        self::assertStringStartsWith('<script>(()=>{const l=["base","shadcn"],d="shadcn",', (string) PaletteScript::render(['base', 'shadcn'], 'shadcn'));
    }

    public function testRendersTheNonce(): void
    {
        self::assertStringStartsWith('<script nonce="a&quot;b">', (string) PaletteScript::render(['base'], nonce: 'a"b'));
    }

    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The palettes must be a list of names.');

        PaletteScript::render([]);
    }

    public function testRejectsANameThatIsNotAnAttributeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A palette name has only lowercase letters, digits and dashes, "Dark Blue" given.');

        PaletteScript::render(['base', 'Dark Blue']);
    }

    public function testRejectsAnUnknownDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The default palette must be one of "base", "shadcn", "blue" given.');

        PaletteScript::render(['base', 'shadcn'], 'blue');
    }

    public function testHasTheKeyOfTheController(): void
    {
        $module = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/dist/phem_palette.js');

        // PhemStorage adds the `phem-` prefix to the key of the controller.
        self::assertStringContainsString('new PhemStorage("'.substr(PaletteScript::KEY, \strlen('phem-')).'")', $module);
    }

    public function testIsATwigFunctionThatIsNotEscaped(): void
    {
        $twig = new Environment(new ArrayLoader(['head' => "<head>{{ palette_script(['base', 'shadcn']) }}</head>"]));
        $twig->addExtension(new PhemExtension(new Manifest(sys_get_temp_dir())));

        self::assertStringStartsWith('<head><script>(()=>{const l=["base","shadcn"],', $twig->render('head'));
    }
}
