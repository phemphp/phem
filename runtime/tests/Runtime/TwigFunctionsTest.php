<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Twig\RephpExtension;

final class TwigFunctionsTest extends TestCase
{
    public function testAttr(): void
    {
        self::assertSame('', (string) RephpExtension::attr('disabled', false));
        self::assertSame('', (string) RephpExtension::attr('title', null));
        self::assertSame('disabled', (string) RephpExtension::attr('disabled', true));
        self::assertSame('src="/a.png?x=1&amp;y=&quot;2&quot;"', (string) RephpExtension::attr('src', '/a.png?x=1&y="2"'));
        self::assertSame('class="a c"', (string) RephpExtension::attr('class', ['a', '', 'c']));
    }

    public function testStyle(): void
    {
        self::assertSame('style="width: 90px; height: 0; z-index: 2; background-color: red; --gap: 4px"', (string) RephpExtension::style(['width' => 90, 'height' => 0, 'zIndex' => 2, 'backgroundColor' => 'red', '--gap' => '4px']));
        self::assertSame('', (string) RephpExtension::style(null));
    }

    public function testKey(): void
    {
        self::assertSame('data-rh-key="a&quot;b"', (string) RephpExtension::key('a"b'));
    }
}
