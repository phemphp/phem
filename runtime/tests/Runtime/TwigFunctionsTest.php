<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Dom\Attributes;

final class TwigFunctionsTest extends TestCase
{
    public function testAttr(): void
    {
        self::assertSame('', (string) Attributes::attr('disabled', false));
        self::assertSame('', (string) Attributes::attr('title', null));
        self::assertSame('disabled', (string) Attributes::attr('disabled', true));
        self::assertSame('src="/a.png?x=1&amp;y=&quot;2&quot;"', (string) Attributes::attr('src', '/a.png?x=1&y="2"'));
        self::assertSame('class="a c"', (string) Attributes::attr('class', ['a', '', 'c']));
    }

    public function testStyle(): void
    {
        self::assertSame('style="width: 90px; height: 0; z-index: 2; background-color: red; --gap: 4px"', (string) Attributes::style(['width' => 90, 'height' => 0, 'zIndex' => 2, 'backgroundColor' => 'red', '--gap' => '4px']));
        self::assertSame('', (string) Attributes::style(null));
    }

    public function testKey(): void
    {
        self::assertSame('data-rh-key="a&quot;b"', (string) Attributes::key('a"b'));
    }
}
