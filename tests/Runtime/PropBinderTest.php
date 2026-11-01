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

use Phem\Attribute\Prop;
use Phem\Binding\PropBinder;
use Phem\Binding\PropError;
use Phem\Html;
use Phem\Tests\Fixtures\Size;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropBinderTest extends TestCase
{
    public function testTheRestPropGetsTheOtherProps(): void
    {
        $component = new class {
            #[Prop] public string $label = '';
            /** @var array<string, mixed> */
            #[Prop(rest: true)] public array $attrs = ['stale' => true];
        };
        $footer = new Html('<b>x</b>');

        (new PropBinder())->assign($component, ['label' => 'Home', 'href' => '/', 'attrs' => 1], ['footer' => $footer]);
        self::assertSame('Home', $component->label);
        self::assertSame(['href' => '/', 'attrs' => 1, 'footer' => $footer], $component->attrs);

        (new PropBinder())->assign($component, ['label' => 'Home']);
        self::assertSame([], $component->attrs);
    }

    public function testAnUnknownPropIsAnErrorWithoutARestProp(): void
    {
        $component = new class {
            #[Prop] public string $label = '';
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('has no prop "href". Its props are: label.');

        (new PropBinder())->assign($component, ['href' => '/']);
    }

    public function testAReadonlyRestPropChangesWhenAPropChanges(): void
    {
        $component = new class {
            /** @var array<string, mixed> */
            #[Prop(rest: true)] public readonly array $attrs; // @phpstan-ignore property.uninitializedReadonly (the PropBinder sets it)
        };
        $binder = new PropBinder();
        $binder->assign($component, ['href' => '/', 'title' => new Html('Home')]);

        self::assertFalse($binder->changesReadonly($component, ['href' => '/', 'title' => new Html('Home')]));
        self::assertTrue($binder->changesReadonly($component, ['href' => '/about', 'title' => new Html('Home')]));
        self::assertTrue($binder->changesReadonly($component, ['href' => '/']));
    }

    public function testAStringBecomesACaseOfABackedEnumProp(): void
    {
        $component = new class {
            #[Prop] public Size $size = Size::Small;
            #[Prop] public ?Size $other = Size::Small;
        };
        $binder = new PropBinder();

        $binder->assign($component, ['size' => 'l', 'other' => null]);
        self::assertSame(Size::Large, $component->size);
        self::assertNull($component->other);

        $binder->assign($component, ['size' => Size::Small]);
        self::assertSame(Size::Small, $component->size);

        self::assertSame(['size' => Size::Large, 'level' => Level::High, 'label' => 'l'], $binder->arguments(__NAMESPACE__.'\\sized', ['size' => 'l', 'level' => '3', 'label' => 'l']));
        self::assertSame(['level' => Level::Low], $binder->arguments(__NAMESPACE__.'\\sized', ['level' => 0]));
        self::assertSame(['size' => Size::Large, 'level' => Level::Low, 'label' => ''], $binder->templateContext(__NAMESPACE__.'\\sized', ['size' => 'l']));
    }

    public function testAWrongValueOfABackedEnumPropIsAnError(): void
    {
        $component = new class {
            #[Prop] public Size $size = Size::Small;
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('has no size "xl". Its values are: s, l.');

        (new PropBinder())->assign($component, ['size' => 'xl']);
    }

    public function testAnIntBackedEnumPropTakesTheTextOfTheInt(): void
    {
        $binder = new PropBinder();

        self::assertSame(['level' => Level::Max], $binder->arguments(__NAMESPACE__.'\\sized', ['level' => '9223372036854775807']));
        self::assertSame(['level' => Level::Low], $binder->arguments(__NAMESPACE__.'\\sized', ['level' => '0']));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function wrongLevels(): iterable
    {
        yield 'a word' => ['high', '<sized> has no level "high". Its values are: 0, 3, 9223372036854775807.'];
        yield 'a leading zero' => ['03', 'has no level "03".'];
        yield 'a plus sign' => ['+3', 'has no level "+3".'];
        yield 'a negative zero' => ['-0', 'has no level "-0".'];
        yield 'an int that overflows' => ['9223372036854775808', 'has no level "9223372036854775808".'];
        yield 'an int that is not a case' => [5, 'has no level 5.'];
        yield 'a bare attribute' => [true, 'has no level true.'];
        yield 'a float' => [3.0, 'has no level 3.0.'];
    }

    #[DataProvider('wrongLevels')]
    public function testAWrongValueOfAnIntBackedEnumPropIsAnError(mixed $level, string $message): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage($message);

        (new PropBinder())->arguments(__NAMESPACE__.'\\sized', ['level' => $level]);
    }

    public function testAReadonlyEnumPropDoesNotChangeWithTheValueOfItsCase(): void
    {
        $component = new class {
            #[Prop] public readonly Size $size; // @phpstan-ignore property.uninitializedReadonly (the PropBinder sets it)
        };
        $binder = new PropBinder();
        $binder->assign($component, ['size' => 'l']);

        self::assertSame(Size::Large, $component->size);
        self::assertFalse($binder->changesReadonly($component, ['size' => 'l']));
        self::assertFalse($binder->changesReadonly($component, ['size' => Size::Large]));
        self::assertTrue($binder->changesReadonly($component, ['size' => 's']));
    }
}

enum Level: int
{
    case Low = 0;
    case High = 3;
    case Max = 9223372036854775807;
}

function sized(Size $size = Size::Large, Level $level = Level::Low, string $label = ''): string
{
    return $size->value.$level->value.$label;
}
