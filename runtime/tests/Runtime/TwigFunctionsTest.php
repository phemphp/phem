<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Dom\Attributes;
use Rephp\Html;
use Rephp\Manifest\Manifest;
use Rephp\Rendering\MarkupResolver;
use Rephp\Rendering\Renderer;
use Rephp\Twig\RephpRuntime;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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

    public function testAttrWithAnInvalidNameIsNotRendered(): void
    {
        self::assertSame('aria-label="a"', (string) Attributes::attr('aria-label', 'a'));
        self::assertSame('xlink:href="a"', (string) Attributes::attr('xlink:href', 'a'));
        self::assertSame('@click="a"', (string) Attributes::attr('@click', 'a'));
        foreach (['x onfocus=alert(1) autofocus', 'a"b', 'a>b', 'a/b', "a\tb", "a\0b", ''] as $name) {
            self::assertSame('', (string) Attributes::attr($name, 'v'), $name);
            self::assertSame('', (string) Attributes::attr($name, true), $name);
        }
    }

    public function testSpreadOfDataAddsNoAttributeAndNoScript(): void
    {
        $attrs = $this->runtime()->attrs(['id' => 'a'], ['x onfocus=alert(1) autofocus' => 'y', 'onclick' => 'alert(2)', 'ONLOAD' => 'alert(3)', 'className' => 'b'], null);

        self::assertSame('id="a" class="b"', (string) $attrs);
    }

    public function testSpreadNeedsAHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->attrs(new class {
            private string $password = 'hash';
        });
    }

    public function testHandlerMustBeAClosure(): void
    {
        self::assertSame('data-rh-on-click="static"', (string) $this->runtime()->attrs(['onClick' => static fn () => null]));

        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->attrs(['onClick' => 'var_dump']);
    }

    public function testStringOutputOfAComponentIsText(): void
    {
        $markup = new MarkupResolver(new Environment(new ArrayLoader()));

        self::assertSame('&lt;b&gt;Ada&lt;/b&gt;', $markup->html('<b>Ada</b>'));
        self::assertSame('<b>Ada</b>', $markup->html(new Html('<b>Ada</b>')));
        self::assertSame('&lt;i&gt;<b>x</b>3', $markup->html(['<i>', new Html('<b>x</b>'), 3]));
        self::assertSame('', $markup->html(null));
    }

    private function runtime(): RephpRuntime
    {
        $twig = new Environment(new ArrayLoader());

        return new RephpRuntime(new Renderer(new Manifest('/nonexistent', ''), new MarkupResolver($twig), []));
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
