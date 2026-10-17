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

namespace Mamu\Tests\Runtime;

use Mamu\Dom\Attributes;
use Mamu\Element;
use Mamu\Html;
use Mamu\Manifest\Manifest;
use Mamu\Rendering\MarkupResolver;
use Mamu\Rendering\Renderer;
use Mamu\Twig\MamuRuntime;
use PHPUnit\Framework\TestCase;
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

    public function testBooleanOfAnAttributeThatTakesTrueOrFalseIsText(): void
    {
        self::assertSame('aria-expanded="false"', (string) Attributes::attr('aria-expanded', false));
        self::assertSame('aria-hidden="true"', (string) Attributes::attr('aria-hidden', true));
        self::assertSame('data-open="false"', (string) Attributes::attr('data-open', false));
        self::assertSame('spellcheck="false"', (string) Attributes::attr('spellcheck', false));
        self::assertSame('contentEditable="true"', (string) Attributes::attr('contentEditable', true));
        self::assertSame('draggable="false"', (string) Attributes::attr('draggable', false));
        self::assertSame('', (string) Attributes::attr('aria-label', null));
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
            public string $password = 'hash';
        });
    }

    public function testHandlerMustBeAClosure(): void
    {
        self::assertSame('data-mamu-on-click="static"', (string) $this->runtime()->attrs(['onClick' => static fn () => null]));

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
        self::assertSame('<b>x</b><b>y</b>', $markup->html([new Html('<b>x</b>'), null, false, new Html('<b>y</b>')]), 'null and false render nothing');
        self::assertInstanceOf(Html::class, $markup->resolve([null, new Html('<b>x</b>')]), 'a list with markup is markup');
        self::assertSame(['a', 'b'], $markup->resolve(['a', 'b']), 'a list without markup is data');
    }

    public function testMarkupRendersWhenATemplatePrintsIt(): void
    {
        $twig = new Environment(new ArrayLoader(['@phpm/a.twig' => '<p>{{ on ? yes : no }}</p>', '@phpm/yes.twig' => '<b>yes</b>', '@phpm/no.twig' => '{{ fail() }}']));
        $twig->addFunction(new \Twig\TwigFunction('fail', static fn () => throw new \LogicException('The hidden branch rendered.')));
        $markup = new MarkupResolver($twig);

        self::assertSame('<p><b>yes</b></p>', $markup->html(new Element('a.twig', ['on' => true, 'yes' => new Element('yes.twig'), 'no' => new Element('no.twig')])));
    }

    private function runtime(): MamuRuntime
    {
        $twig = new Environment(new ArrayLoader());

        return new MamuRuntime(new Renderer(new Manifest('/nonexistent', ''), new MarkupResolver($twig), []));
    }

    public function testStyle(): void
    {
        self::assertSame('style="width: 90px; height: 0; z-index: 2; background-color: red; --gap: 4px"', (string) Attributes::style(['width' => 90, 'height' => 0, 'zIndex' => 2, 'backgroundColor' => 'red', '--gap' => '4px']));
        self::assertSame('', (string) Attributes::style(null));
    }

    public function testStyleOfDataAddsNoDeclaration(): void
    {
        $style = Attributes::style([
            'color' => 'red; position: fixed',
            'background' => 'url("data:image/png;base64,AA==")',
            'content' => "'a;b'",
            'width' => 'calc(1px',
            'margin' => '0) ; top: 0; (',
            'x: 1; top' => '0',
            'fontFamily' => 'serif',
        ]);

        self::assertSame('style="background: url(&quot;data:image/png;base64,AA==&quot;); content: &#039;a;b&#039;; font-family: serif"', (string) $style);
    }

    public function testKey(): void
    {
        self::assertSame('data-mamu-key="a&quot;b"', (string) Attributes::key('a"b'));
    }

    public function testKeyedAddsTheKeyAsText(): void
    {
        self::assertSame(' <li data-mamu-key="$10">x</li>', Attributes::keyed(' <li>x</li>', '$10'));
        self::assertSame('<li data-mamu-key="a\\1&amp;$1">x</li>', Attributes::keyed('<li>x</li>', 'a\\1&$1'));
        self::assertSame('<li data-mamu-key="a'."\u{FFFD}".'">x</li>', Attributes::keyed('<li>x</li>', "a\xFF"), 'invalid UTF-8');
        self::assertSame('text', Attributes::keyed('text', 'k'));
    }
}
