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

use Phem\ClassList;
use Phem\Dom\Attributes;
use Phem\Dom\ClassAttribute;
use Phem\Dom\ClassMerger;
use Phem\Dom\DynamicTag;
use Phem\Dom\TailwindClassMerger;
use Phem\Element;
use Phem\Html;
use Phem\HtmlList;
use Phem\Manifest\Manifest;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Twig\PhemRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TalesFromADev\TailwindMerge\TailwindMerge;
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

        foreach (['x onfocus=alert(1) autofocus', 'a"b', 'a>b', 'a/b', "a\tb", "a\0b", "a\n", ''] as $name) {
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

    public function testKeyFilter(): void
    {
        self::assertSame('data-phem-key-filter="Enter"', (string) Attributes::keyFilter('Enter'));
        self::assertSame('data-phem-key-filter="ctrl+s Escape"', (string) Attributes::keyFilter('ctrl+s Escape'));
        self::assertSame('', (string) Attributes::keyFilter(null));
        self::assertSame('', (string) Attributes::keyFilter(false));
        self::assertSame('id="a" data-phem-key-filter="Enter"', (string) $this->runtime()->attrs(['id' => 'a', 'phem:key' => 'Enter']), 'in a spread');
    }

    #[DataProvider('invalidKeyFilters')]
    public function testKeyFilterTakesTheKeys(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attributes::keyFilter($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidKeyFilters(): iterable
    {
        yield 'an empty string' => [''];
        yield 'a list' => [['Enter']];
        yield 'an unknown modifier' => ['cmd+s'];
        yield 'no key' => ['ctrl+'];
        yield 'a name with other characters' => ['Enter" onfocus="x'];
    }

    public function testShortcut(): void
    {
        self::assertSame('data-phem-shortcut="mod+k"', (string) Attributes::shortcut('mod+k'));
        self::assertSame('data-phem-shortcut="j ArrowDown"', (string) Attributes::shortcut('j ArrowDown'));
        self::assertSame('', (string) Attributes::shortcut(null));
        self::assertSame('', (string) Attributes::shortcut(false));
        self::assertSame('id="a" data-phem-shortcut="/"', (string) $this->runtime()->attrs(['id' => 'a', 'phem:shortcut' => '/']), 'in a spread');
    }

    #[DataProvider('invalidKeyFilters')]
    public function testShortcutTakesTheKeys(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attributes::shortcut($value);
    }

    public function testPoll(): void
    {
        self::assertSame('data-phem-poll="30000"', (string) Attributes::poll(30000));
        self::assertSame('data-phem-poll="1"', (string) Attributes::poll(1));
        self::assertSame('', (string) Attributes::poll(null));
        self::assertSame('', (string) Attributes::poll(false));
        self::assertSame('id="a" data-phem-poll="5000"', (string) $this->runtime()->attrs(['id' => 'a', 'phem:poll' => 5000]), 'in a spread');
    }

    #[DataProvider('invalidPolls')]
    public function testPollTakesMilliseconds(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"phem:poll" takes a number of milliseconds (1 or more)');
        Attributes::poll($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPolls(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative number' => [-1000];
        yield 'a string number' => ['30000'];
        yield 'a float' => [1.5];
        yield 'true' => [true];
    }

    public function testFlagsInASpread(): void
    {
        self::assertSame('data-phem-stop data-phem-prevent', (string) $this->runtime()->attrs(['phem:stop' => true, 'phem:prevent' => true]));
        self::assertSame('', (string) $this->runtime()->attrs(['phem:stop' => false, 'phem:prevent' => 'yes']));
        self::assertSame('data-phem-scroll-into-view data-phem-keep-scroll', (string) $this->runtime()->attrs(['phem:scroll-into-view' => true, 'phem:keep-scroll' => true]));
        self::assertSame('data-phem-keep-scroll="end"', (string) $this->runtime()->attrs(['phem:keep-scroll' => 'end']));
        self::assertSame('', (string) $this->runtime()->attrs(['phem:scroll-into-view' => false, 'phem:keep-scroll' => null]));
    }

    public function testKeepScrollTakesTrueOrEnd(): void
    {
        self::assertSame('data-phem-keep-scroll', (string) Attributes::keepScroll(true));
        self::assertSame('data-phem-keep-scroll="end"', (string) Attributes::keepScroll('end'));
        self::assertSame('', (string) Attributes::keepScroll(false));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"phem:keep-scroll" takes true, \'end\', false or null, "top" given.');

        Attributes::keepScroll('top');
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
        $twig = new Environment(new ArrayLoader(['@phemc/a.twig' => '<p>{{ on ? yes : no }}</p>', '@phemc/yes.twig' => '<b>yes</b>', '@phemc/no.twig' => '{{ fail() }}']));
        $twig->addFunction(new \Twig\TwigFunction('fail', static fn () => throw new \LogicException('The hidden branch rendered.')));
        $markup = new MarkupResolver($twig);

        self::assertSame('<p><b>yes</b></p>', $markup->html(new Element('a.twig', ['on' => true, 'yes' => new Element('yes.twig'), 'no' => new Element('no.twig')])));
    }

    public function testMarkupInDataResolvesAndTheOtherDataIsNotCopied(): void
    {
        $markup = new MarkupResolver(new Environment(new ArrayLoader(['@phemc/b.twig' => '<b>{{ name }}</b>'])));
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

    public function testClass(): void
    {
        self::assertSame('class="a c"', (string) $this->runtime()->class(['a', '', 'c']));
        self::assertSame('class="btn active"', (string) $this->runtime()->class(['btn', ['active' => true, 'off' => false]]), 'the list of a quoted value with an expression');
        self::assertSame('class="px-4 py-2"', (string) $this->runtime()->class(ClassList::of('px-4 py-2')));
        self::assertSame('', (string) $this->runtime()->class(null));
    }

    public function testSpreadKeepsTheLastClassOfTwoPlainValues(): void
    {
        self::assertSame('class="override"', (string) $this->runtime()->attrs(['className' => 'loud'], ['className' => 'override']));
        self::assertSame('class="b"', (string) $this->runtime()->attrs(['class' => 'a'], ['className' => 'b']), '`class` and `className` are the same attribute');
    }

    public function testSpreadMergesAClassListWithTheClassOfTheCaller(): void
    {
        $runtime = $this->runtime(new TailwindClassMerger(new TailwindMerge()));

        self::assertSame('type="button" class="px-4 py-2 bg-red-500"', (string) $runtime->attrs(['type' => 'button', 'className' => ClassList::of('px-4 py-2 bg-blue-500')], ['className' => 'bg-red-500']), 'the caller wins a conflict');
        self::assertSame('class="w-full bg-blue-500"', (string) $runtime->attrs(['className' => 'w-full bg-red-500'], ['className' => ClassList::of('bg-blue-500')]), 'a ClassList after the spread wins a conflict');
        self::assertSame('class="px-4"', (string) $runtime->attrs(['className' => ClassList::of('px-4')], ['className' => null]), 'a null class of the caller');
        self::assertSame('class="a b c"', (string) $this->runtime()->attrs(['class' => ClassList::of('a b')], ['className' => ['b', 'c' => true]]), 'the default merger removes the duplicates');
    }

    private function runtime(?ClassMerger $merger = null): PhemRuntime
    {
        $twig = new Environment(new ArrayLoader());
        $classes = null !== $merger ? new ClassAttribute($merger) : new ClassAttribute();

        return new PhemRuntime(new Renderer(new Manifest('/nonexistent'), new MarkupResolver($twig), []), classes: $classes);
    }

    public function testDynamicTag(): void
    {
        self::assertSame('a', (string) DynamicTag::of('a'));
        self::assertSame('my-card', DynamicTag::of('my-card')->name);
        self::assertSame('Docs</a>', (string) DynamicTag::close(DynamicTag::of('a'), 'Docs'));
        self::assertSame('</section>', (string) DynamicTag::close(DynamicTag::of('section')));
        self::assertSame('', (string) DynamicTag::close(DynamicTag::of('img')), 'a void element has no end tag');
        self::assertSame('', (string) DynamicTag::close(DynamicTag::of('IMG'), "\n  "), 'only whitespace in a void element');
    }

    #[DataProvider('invalidDynamicTags')]
    public function testDynamicTagRefusesAnInvalidTag(mixed $tag, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DynamicTag::of($tag);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidDynamicTags(): iterable
    {
        yield 'an attribute in the name' => ['a onclick=x', '"a onclick=x" is not a valid tag name for <Dynamic>.'];
        yield 'an end of tag' => ['a>', '"a>" is not a valid tag name for <Dynamic>.'];
        yield 'a final newline' => ["script\n", "\"script\n\" is not a valid tag name for <Dynamic>."];
        yield 'a final newline after a name' => ["a\n", "\"a\n\" is not a valid tag name for <Dynamic>."];
        yield 'empty' => ['', '"" is not a valid tag name for <Dynamic>.'];
        yield 'not a string' => [null, '"null" is not a valid tag name for <Dynamic>.'];
        yield 'an element of Phem' => ['phem-c', '"phem-c" is not a valid tag name for <Dynamic>.'];
        yield 'the fallback of a Suspense' => ['PHEM-suspense', '"PHEM-suspense" is not a valid tag name for <Dynamic>.'];
        yield 'a script' => ['script', '<Dynamic> cannot render a <script>: write it as static markup.'];
        yield 'a style' => ['STYLE', '<Dynamic> cannot render a <style>: write it as static markup.'];
        yield 'an iframe (srcdoc)' => ['iframe', '<Dynamic> cannot render a <iframe>: write it as static markup.'];
        yield 'an object (data)' => ['Object', '<Dynamic> cannot render a <object>: write it as static markup.'];
        yield 'a base (href)' => ['base', '<Dynamic> cannot render a <base>: write it as static markup.'];
    }

    public function testDynamicVoidTagRefusesChildren(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('<input> is a void element: <Dynamic tag="input"> cannot have children.');

        DynamicTag::close(DynamicTag::of('input'), 'Text');
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
