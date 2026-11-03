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

use Phem\DependencyInjection\TailwindTheme;
use PHPUnit\Framework\TestCase;

final class TailwindThemeTest extends TestCase
{
    private const DIR = __DIR__.'/../Fixtures/tailwind';

    public function testTheNamesOfTheThemeBlocks(): void
    {
        $theme = TailwindTheme::fromCss(self::DIR.'/app.css');

        self::assertSame([
            'font-weight' => ['heavy'],
            'text' => ['caption', 'price-lg'],
            'text-shadow' => ['glow'],
            'animate' => ['wiggle'],
            'radius' => ['card'],
            'spacing' => ['gutter'],
        ], $theme->names, 'no sub-property, no color or font, no variable of a nested block or of a rule');
    }

    public function testTheFilesOfTheRelativeImports(): void
    {
        $theme = TailwindTheme::fromCss(self::DIR.'/app.css');

        self::assertSame([realpath(self::DIR.'/app.css'), realpath(self::DIR.'/theme.css')], $theme->files, 'a file is read once, also in an import cycle');
    }

    public function testAMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The CSS file "'.self::DIR.'/missing.css" does not exist.');

        TailwindTheme::fromCss(self::DIR.'/missing.css');
    }

    public function testAMissingImport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The CSS file "'.realpath(self::DIR.'/broken.css').'" imports "./missing.css", which does not exist.');

        TailwindTheme::fromCss(self::DIR.'/broken.css');
    }
}
