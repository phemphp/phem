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

use Phem\Dom\Attributes;
use Phem\Element;
use Phem\Html;
use Phem\HtmlList;
use Phem\Manifest\Manifest;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Twig\PhemRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A `javascript:` URL from data in a URL attribute runs as a script on a click, as React
     * blocks it: in a spread and in a single attribute.
     */
    #[DataProvider('scriptUrls')]
    public function testUrlAttributeOfDataRunsNoScript(string $name, string $url): void
    {
        foreach ([(string) Attributes::attr($name, $url), (string) $this->runtime()->attrs([$name => $url])] as $html) {
            // The URL as the browser reads it: no entities, no control characters or spaces at the ends, and no tabs or line breaks.
            $value = 1 === preg_match('/="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5) : '';
            $value = str_replace(["\t", "\n", "\r"], '', trim($value, "\x00..\x20"));

            self::assertDoesNotMatchRegularExpression('/^javascript:/i', $value, $html);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function scriptUrls(): iterable
    {
        yield ['href', 'javascript:alert(1)'];
        yield ['href', ' JavaScript:alert(1)'];
        yield ['href', "java\tscript:alert(1)"];
        yield ['src', 'javascript:alert(1)'];
        yield ['action', 'javascript:alert(1)'];
        yield ['formaction', 'javascript:alert(1)'];
        yield ['xlink:href', 'javascript:alert(1)'];
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
        self::assertSame('data-phem-on-click="static"', (string) $this->runtime()->attrs(['onClick' => static fn () => null]));

        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->attrs(['onClick' => 'var_dump']);
    }

    public function testLoadingTakesAFunction(): void
    {
        self::assertSame('data-phem-loading="static" hidden', (string) $this->runtime()->loading(static fn () => null));
        self::assertSame('', (string) $this->runtime()->loading(null));
        self::assertSame('id="a" data-phem-loading="static" hidden', (string) $this->runtime()->attrs(['id' => 'a', 'phem:loading' => static fn () => null]), 'in a spread');

        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->attrs(['phem:loading' => 'save']);
    }

    public function testProgressTakesAFunction(): void
    {
        self::assertSame('data-phem-progress="static" hidden', (string) $this->runtime()->progress(static fn () => null));
        self::assertSame('', (string) $this->runtime()->progress(null));
        self::assertSame('max="100" data-phem-progress="static" hidden', (string) $this->runtime()->attrs(['max' => '100', 'phem:progress' => static fn () => null]), 'in a spread');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "phem:progress" handler must be a function');
        $this->runtime()->attrs(['phem:progress' => 'save']);
    }

    public function testTiming(): void
    {
        self::assertSame('data-phem-debounce="300"', (string) Attributes::timing('debounce', 300));
        self::assertSame('data-phem-debounce="blur"', (string) Attributes::timing('debounce', 'blur'));
        self::assertSame('data-phem-debounce="0"', (string) Attributes::timing('debounce', 0));
        self::assertSame('data-phem-throttle="500"', (string) Attributes::timing('throttle', 500));
        self::assertSame('', (string) Attributes::timing('debounce', null));
        self::assertSame('', (string) Attributes::timing('throttle', false));
        self::assertSame('id="a" data-phem-debounce="300"', (string) $this->runtime()->attrs(['id' => 'a', 'phem:debounce' => 300]), 'in a spread');
    }

    #[DataProvider('invalidTimings')]
    public function testTimingTakesMillisecondsOrBlur(string $kind, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attributes::timing($kind, $value);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidTimings(): iterable
    {
        yield 'a negative number' => ['debounce', -1];
        yield 'a string number' => ['debounce', '300'];
        yield 'a float' => ['debounce', 1.5];
        yield 'another string' => ['debounce', '300" onfocus="x'];
        yield 'blur for throttle' => ['throttle', 'blur'];
        yield 'another kind' => ['delay', 300];
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

    public function testMarkupInDataResolvesAndTheOtherDataIsNotCopied(): void
    {
        $markup = new MarkupResolver(new Environment(new ArrayLoader(['@phpm/b.twig' => '<b>{{ name }}</b>'])));
        $rows = [];

        for ($i = 0; $i < 5000; ++$i) {
            $rows[] = ['id' => $i, 'name' => 'Name '.$i, 'tags' => ['a', 'b']];
        }

        $resolved = $markup->resolve(['rows' => $rows, 'label' => ['text' => new Element('b.twig', ['name' => 'Ada'])], 'items' => [new Element('b.twig', ['name' => 'Grace'])]]);

        self::assertSame($rows, $resolved['rows']);
        self::assertInstanceOf(Html::class, $resolved['label']['text'], 'markup in a hash');
        self::assertSame('<b>Ada</b>', (string) $resolved['label']['text']);
        self::assertInstanceOf(HtmlList::class, $resolved['items'], 'a list of markup');
        self::assertSame('<b>Grace</b>', (string) $resolved['items']);

        // A copy of the rows takes more than 1 MB.
        $before = memory_get_usage();
        $same = $markup->resolve(['rows' => $rows, 'title' => 'Rows']);
        self::assertLessThan(10_000, memory_get_usage() - $before, 'the data is the same array');
        self::assertSame(['rows' => $rows, 'title' => 'Rows'], $same);
    }

    private function runtime(): PhemRuntime
    {
        $twig = new Environment(new ArrayLoader());

        return new PhemRuntime(new Renderer(new Manifest('/nonexistent'), new MarkupResolver($twig), []));
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
        self::assertSame('data-phem-key="a&quot;b"', (string) Attributes::key('a"b'));
    }

    public function testKeyedAddsTheKeyAsText(): void
    {
        self::assertSame(' <li data-phem-key="$10">x</li>', Attributes::keyed(' <li>x</li>', '$10'));
        self::assertSame('<li data-phem-key="a\\1&amp;$1">x</li>', Attributes::keyed('<li>x</li>', 'a\\1&$1'));
        self::assertSame('<li data-phem-key="a'."\u{FFFD}".'">x</li>', Attributes::keyed('<li>x</li>', "a\xFF"), 'invalid UTF-8');
        self::assertSame('text', Attributes::keyed('text', 'k'));
    }
}
