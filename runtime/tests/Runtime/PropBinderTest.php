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

use Mamu\Attribute\Prop;
use Mamu\Binding\PropBinder;
use Mamu\Binding\PropError;
use Mamu\Html;
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
}
