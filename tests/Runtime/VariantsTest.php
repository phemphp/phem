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
use Phem\Variants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Phem\variants;

enum VariantsTone: string
{
    case Primary = 'primary';
    case Outline = 'outline';
    case Ghost = 'ghost';
}

enum VariantsSize
{
    case Sm;
    case Lg;
}

final class VariantsTest extends TestCase
{
    public function testTheClassesAreTheBaseThenEachAxisThenTheCompoundVariants(): void
    {
        $classes = $this->button()->apply(size: VariantsSize::Lg, tone: VariantsTone::Outline);

        self::assertInstanceOf(ClassList::class, $classes);
        self::assertSame(['px-4', 'py-2', 'border', 'border-blue-500', 'text-lg', 'border-2'], $classes->classes);
    }

    public function testACompoundVariantNeedsAllItsConditions(): void
    {
        self::assertSame(['px-4', 'py-2', 'border', 'border-blue-500', 'text-sm'], $this->button()->apply(tone: VariantsTone::Outline, size: VariantsSize::Sm)->classes);
    }

    public function testAConditionCanBeAListOfValues(): void
    {
        $recipe = variants(
            variants: ['tone' => ['primary' => 'a', 'outline' => 'b', 'ghost' => 'c']],
            compound: [[['tone' => [VariantsTone::Primary, 'outline']], 'solid']],
        );

        self::assertSame(['a', 'solid'], $recipe->apply(tone: VariantsTone::Primary)->classes);
        self::assertSame(['b', 'solid'], $recipe->apply(tone: 'outline')->classes);
        self::assertSame(['c'], $recipe->apply(tone: VariantsTone::Ghost)->classes);
    }

    public function testAnAxisWithoutAValueAddsNoClass(): void
    {
        self::assertSame(['px-4', 'py-2'], $this->button()->apply()->classes);
        self::assertSame(['px-4', 'py-2', 'text-sm'], $this->button()->apply(tone: null, size: VariantsSize::Sm)->classes);
    }

    public function testAValueWithAnEmptyStringAddsNoClass(): void
    {
        $recipe = variants(variants: ['tone' => ['primary' => 'a', 'ghost' => '']]);

        self::assertSame([], $recipe->apply(tone: VariantsTone::Ghost)->classes);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function keys(): iterable
    {
        yield 'a numeric string' => ['1', 'one'];
        yield 'an int' => [1, 'one'];
        yield 'a float' => [1.5, 'one and a half'];
        yield 'a Stringable' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 'big';
            }
        }, 'big'];
    }

    #[DataProvider('keys')]
    public function testAScalarOrAStringableGivesItsString(mixed $value, string $class): void
    {
        $recipe = variants(variants: ['size' => ['1' => 'one', '1.5' => 'one and a half', 'big' => 'big']]);

        self::assertSame(explode(' ', $class), $recipe->apply(size: $value)->classes);
    }

    public function testABoolGivesTrueOrFalse(): void
    {
        $recipe = variants(variants: ['disabled' => ['true' => 'opacity-50', 'false' => 'cursor-pointer']]);

        self::assertSame(['opacity-50'], $recipe->apply(disabled: true)->classes);
        self::assertSame(['cursor-pointer'], $recipe->apply(disabled: false)->classes);
    }

    public function testTheClassesTakeTheInputsOfClassList(): void
    {
        $recipe = variants(base: ['btn', 'active' => true], variants: ['size' => [1 => ['one', 'hidden' => false]]]);

        self::assertSame(['btn', 'active', 'one'], $recipe->apply(size: 1)->classes);
    }

    public function testAnUnknownAxisIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The variants have no axis "color". The axes are "tone", "size".');

        $this->button()->apply(color: 'red');
    }

    public function testAnUnknownAxisWithoutAValueIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The variants have no axis "color".');

        $this->button()->apply(color: null);
    }

    public function testAValueWithoutClassesIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The axis "tone" has no value "ghost". The values are "primary", "outline".');

        $this->button()->apply(tone: VariantsTone::Ghost);
    }

    public function testAnUnknownValueInACompoundVariantIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The axis "tone" has no value "dangr".');

        variants(variants: ['tone' => ['danger' => 'x']], compound: [[['tone' => ['danger', 'dangr']], 'y']]);
    }

    public function testANullConditionIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A condition of the axis "tone" takes a value, null given.');

        variants(variants: ['tone' => ['danger' => 'x']], compound: [[['tone' => null], 'y']]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function compoundShapes(): iterable
    {
        yield 'the cva shape' => [['tone' => 'danger', 'class' => 'y']];
        yield 'no classes' => [[['tone' => 'danger']]];
        yield 'no conditions' => [[[], 'y']];
        yield 'a string' => ['y'];
    }

    #[DataProvider('compoundShapes')]
    public function testACompoundVariantIsAPairOfConditionsAndClasses(mixed $compound): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A compound variant is a pair of conditions and classes');

        variants(variants: ['tone' => ['danger' => 'x']], compound: [$compound]);
    }

    public function testAnUnknownAxisInACompoundVariantIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The variants have no axis "color".');

        new Variants(variants: ['size' => []], compound: [[['color' => 'red'], 'x']]);
    }

    public function testAValueOfAnotherTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A variant takes an enum, a string, an int or a bool, "array" given.');

        $this->button()->apply(size: []);
    }

    private function button(): Variants
    {
        return variants(
            base: 'px-4 py-2',
            variants: [
                'tone' => ['primary' => 'bg-blue-500 text-white', 'outline' => 'border border-blue-500'],
                'size' => ['Sm' => 'text-sm', 'Lg' => 'text-lg'],
            ],
            compound: [
                [['tone' => VariantsTone::Outline, 'size' => VariantsSize::Lg], 'border-2'],
            ],
        );
    }
}
