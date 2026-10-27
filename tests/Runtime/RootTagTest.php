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

use Phem\Rendering\Fiber;
use Phem\Rendering\RootTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootTagTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function oneElement(): iterable
    {
        yield 'an element' => ['<p>Hi</p>', '<p data-phem-c="phem1">Hi</p>'];
        yield 'attributes' => ['<button id="b" class="x">Go</button>', '<button id="b" class="x" data-phem-c="phem1">Go</button>'];
        yield 'a > in a value' => ['<a title="a > b">x</a>', '<a title="a > b" data-phem-c="phem1">x</a>'];
        yield 'whitespace around it' => ["\n  <tr><td>1</td></tr>\n", '<tr data-phem-c="phem1"><td>1</td></tr>'];
        yield 'nested elements of the same name' => ['<div><div>a</div><div>b</div></div>', '<div data-phem-c="phem1"><div>a</div><div>b</div></div>'];
        yield 'a void element' => ['<input name="q">', '<input name="q" data-phem-c="phem1">'];
        yield 'a self-closed element' => ['<svg><path d="M0" /></svg>', '<svg data-phem-c="phem1"><path d="M0" /></svg>'];
        yield 'a self-closed root' => ['<img src="a.png" />', '<img src="a.png" data-phem-c="phem1" />'];
        yield 'void elements inside' => ['<p>a<br>b<img src="x"></p>', '<p data-phem-c="phem1">a<br>b<img src="x"></p>'];
        yield 'a comment with tags' => ['<div><!-- </div><p> --></div>', '<div data-phem-c="phem1"><!-- </div><p> --></div>'];
        yield 'a script with tags' => ['<div><script>if (a < b) "</div>"</script></div>', '<div data-phem-c="phem1"><script>if (a < b) "</div>"</script></div>'];
        yield 'a < in the text' => ['<p>1 < 2</p>', '<p data-phem-c="phem1">1 < 2</p>'];
        yield 'an option' => ['<option value="1">One</option>', '<option value="1" data-phem-c="phem1">One</option>'];
        yield 'a child component inside' => ['<ul><li data-phem-c="phem2">a</li></ul>', '<ul data-phem-c="phem1"><li data-phem-c="phem2">a</li></ul>'];
    }

    #[DataProvider('oneElement')]
    public function testTheFiberIdGoesOnTheRootElement(string $html, string $expected): void
    {
        self::assertSame($expected, self::wrapped($html));
    }

    /** @return iterable<string, array{0: string}> */
    public static function notOneElement(): iterable
    {
        yield 'text' => ['Hello'];
        yield 'empty' => [''];
        yield 'two elements' => ['<p>a</p><p>b</p>'];
        yield 'text after the element' => ['<p>a</p> and more'];
        yield 'text before the element' => ['and <p>a</p>'];
        yield 'another component' => ['<li data-phem-c="phem2">a</li>'];
        yield 'another component with a wrapper' => ['<phem-c data-phem-c="phem2">a</phem-c>'];
        yield 'a kept component' => ['<template data-phem-keep="phem2"></template>'];
        yield 'an element that does not close' => ['<div><p>a</p>'];
        yield 'a comment' => ['<!-- a --><p>a</p>'];
    }

    #[DataProvider('notOneElement')]
    public function testAnHtmlThatIsNotOneElementHasAWrapper(string $html): void
    {
        self::assertNull(RootTag::find($html));
        self::assertSame('<phem-c data-phem-c="phem1">'.$html.'</phem-c>', self::wrapped($html));
    }

    public function testAKeptFiberIsATemplatePlaceholder(): void
    {
        $fiber = new Fiber('phem1', '/A#0', 'A');
        $fiber->rendered('');
        $fiber->kept = true;

        self::assertSame('<template data-phem-keep="phem1"></template>', $fiber->wrapped());
    }

    public function testTheBroadcastKeysGoNextToTheFiberId(): void
    {
        $fiber = new Fiber('phem1', '/A#0', 'A');
        $fiber->broadcast = ['k1', 'k2'];
        $fiber->rendered('<tr><td>1</td></tr>');

        self::assertSame('<tr data-phem-c="phem1" data-phem-broadcast="k1 k2"><td>1</td></tr>', $fiber->wrapped());
    }

    private static function wrapped(string $html): string
    {
        $fiber = new Fiber('phem1', '/A#0', 'A');
        $fiber->rendered($html);

        return $fiber->wrapped();
    }
}
