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

use Phem\Binding\SpreadProps;
use Phem\ClassList;
use Phem\Dom\ClassAttribute;
use Phem\Dom\TailwindClassMerger;
use Phem\Dom\UniqueClassMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TalesFromADev\TailwindMerge\TailwindMerge;

use function Phem\classes;

final class ClassListTest extends TestCase
{
    /** @return iterable<string, array{list<mixed>, list<string>}> */
    public static function inputs(): iterable
    {
        yield 'a string' => [['px-4 py-2'], ['px-4', 'py-2']];
        yield 'white space' => [["  a\tb\n c  "], ['a', 'b', 'c']];
        yield 'strings' => [['a', 'b c'], ['a', 'b', 'c']];
        yield 'a list' => [[['a', 'b']], ['a', 'b']];
        yield 'a nested list' => [[['a', ['b', ['c d']]]], ['a', 'b', 'c', 'd']];
        yield 'a map of conditions' => [[['active' => true, 'hidden' => false, 'big bold' => 1]], ['active', 'big', 'bold']];
        yield 'a list with conditions' => [[['btn', 'active' => true, 'off' => null]], ['btn', 'active']];
        yield 'no class' => [[null, false, true, ''], []];
        yield 'a number' => [[1], ['1']];
        yield 'a ClassList' => [[ClassList::of('a b'), 'c'], ['a', 'b', 'c']];
        yield 'the duplicates stay' => [['a b', 'a'], ['a', 'b', 'a']];
        yield 'a Stringable' => [[new class implements \Stringable {
            public function __toString(): string
            {
                return 'x y';
            }
        }], ['x', 'y']];
    }

    /**
     * @param list<mixed>  $inputs
     * @param list<string> $classes
     */
    #[DataProvider('inputs')]
    public function testTheInputsAreAsInClsx(array $inputs, array $classes): void
    {
        self::assertSame($classes, ClassList::of(...$inputs)->classes);
    }

    public function testTheFunctionGivesAClassList(): void
    {
        self::assertSame(['a', 'active'], classes('a', ['active' => true])->classes);
    }

    public function testAnObjectIsNotAClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A class takes a string, a list, a map of conditions or a ClassList, "stdClass" given.');

        ClassList::of(new \stdClass());
    }

    public function testTwoPlainValuesKeepTheLastValue(): void
    {
        self::assertSame('b', ClassList::merge('a', 'b'));
        self::assertSame(['b'], ClassList::merge(['a'], ['b']));
        self::assertNull(ClassList::merge('a', null));
    }

    public function testAClassListMergesWithTheOtherValue(): void
    {
        $merged = ClassList::merge(ClassList::of('px-4 py-2'), 'bg-red-500');
        self::assertInstanceOf(ClassList::class, $merged);
        self::assertSame(['px-4', 'py-2', 'bg-red-500'], $merged->classes, 'the classes of the caller come after the defaults');

        $merged = ClassList::merge('w-full', ClassList::of('px-4'));
        self::assertInstanceOf(ClassList::class, $merged);
        self::assertSame(['w-full', 'px-4'], $merged->classes, 'a ClassList after the spread comes last, so it wins a conflict');

        $merged = ClassList::merge(ClassList::of('a'), null);
        self::assertInstanceOf(ClassList::class, $merged);
        self::assertSame(['a'], $merged->classes);
    }

    public function testTheUniqueMergerRemovesTheDuplicates(): void
    {
        self::assertSame('a b c', (new UniqueClassMerger())->merge(['a', 'b', 'a', 'c', 'b']));
        self::assertSame('', (new UniqueClassMerger())->merge([]));
    }

    public function testTheTailwindMergerRemovesTheConflictingClasses(): void
    {
        $merger = new TailwindClassMerger(new TailwindMerge());

        self::assertSame('py-2 bg-red-500 px-4', $merger->merge(['px-2', 'py-2', 'bg-blue-500', 'bg-red-500', 'px-4']));
        self::assertSame('p-2 text-sm', $merger->merge(['p-2', 'text-sm']));
    }

    public function testTheTailwindMergerTakesThePrefix(): void
    {
        $merger = new TailwindClassMerger(new TailwindMerge(['prefix' => 'tw']));

        self::assertSame('tw:px-4', $merger->merge(['tw:px-2', 'tw:px-4']));
    }

    public function testTheClassAttribute(): void
    {
        $attribute = new ClassAttribute();

        self::assertSame('class="btn"', (string) $attribute->render('btn'));
        self::assertSame('class="a  b"', (string) $attribute->render('a  b'), 'a string renders as it is');
        self::assertSame('class="a c"', (string) $attribute->render(['a', '', 'c', 'a']), 'a class array goes through the merger');
        self::assertSame('class="btn active"', (string) $attribute->render(['btn', 'active' => true, 'off' => false]));
        self::assertSame('class="px-4 bg-red-500"', (string) $attribute->render(ClassList::of('px-4', 'bg-red-500')));
        self::assertSame('class="a&quot;&gt;"', (string) $attribute->render(ClassList::of('a">')));
        self::assertSame('', (string) $attribute->render(null));
        self::assertSame('', (string) $attribute->render(false));
        self::assertSame('', (string) $attribute->render([]));
        self::assertSame('', (string) $attribute->render(ClassList::of(['active' => false])));
    }

    public function testTheClassAttributeUsesTheMerger(): void
    {
        $attribute = new ClassAttribute(new TailwindClassMerger(new TailwindMerge()));

        self::assertSame('class="py-2 bg-red-500"', (string) $attribute->render(ClassList::merge(ClassList::of('py-2 bg-blue-500'), 'bg-red-500')));
        self::assertSame('class="py-2 px-4"', (string) $attribute->render(['px-2 py-2', 'px-4']), 'a class array goes through the merger');
        self::assertSame('class="px-2 px-4"', (string) $attribute->render('px-2 px-4'), 'a string does not go through the merger');
    }

    public function testTheSpreadPropsKeepTheLastValue(): void
    {
        self::assertSame(['count' => 7, 'id' => 'b', 'type' => 'button'], SpreadProps::merge(['count' => 7, 'id' => 'a'], ['id' => 'b'], null, ['type' => 'button']));
        self::assertSame(['className' => 'b'], SpreadProps::merge(['className' => 'a'], ['className' => 'b']), 'two plain values');
    }

    public function testTheClassPropsOfASpreadMerge(): void
    {
        $props = SpreadProps::merge(['className' => ClassList::of('px-4')], new \ArrayIterator(['className' => 'w-full', 'id' => 'x']));
        self::assertInstanceOf(ClassList::class, $props['className']);
        self::assertSame(['px-4', 'w-full'], $props['className']->classes);
        self::assertSame('x', $props['id']);

        $props = SpreadProps::merge(['class' => 'a'], ['class' => ClassList::of('b')]);
        self::assertInstanceOf(ClassList::class, $props['class']);
        self::assertSame(['a', 'b'], $props['class']->classes);

        $props = SpreadProps::merge(['className' => ClassList::of('a')], ['class' => 'b']);
        self::assertSame(['className', 'class'], array_keys($props), '`class` and `className` merge on the element (phem_attrs)');
    }

    public function testASpreadOfPropsNeedsAHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A spread (`{{ ...props }}`) needs a hash of props, "string" given.');

        SpreadProps::merge(['a' => 1], 'rest');
    }
}
