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

use Phem\Attribute\Context;
use Phem\Attribute\Prop;
use Phem\Attribute\Provide;
use Phem\Binding\PropBinder;
use Phem\Metadata\ContextMetadata;
use Phem\Tests\Fixtures\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextMetadataTest extends TestCase
{
    public function testTheDependenciesOfAClassComponent(): void
    {
        $component = new class {
            #[Context]
            public Theme $theme;
            #[Context]
            public ?\Countable $items = null;
            #[Context]
            public Theme $fallback = Theme::Dark;
        };

        $dependencies = new ContextMetadata()->properties($component::class);

        self::assertSame(['theme', 'items', 'fallback'], array_keys($dependencies));
        self::assertSame([Theme::class, true], [$dependencies['theme']->type, $dependencies['theme']->required]);
        self::assertSame([\Countable::class, false, null], [$dependencies['items']->type, $dependencies['items']->required, $dependencies['items']->default]);
        self::assertSame([false, Theme::Dark], [$dependencies['fallback']->required, $dependencies['fallback']->default]);
    }

    public function testAContextParameterIsNotAProp(): void
    {
        $dependencies = new ContextMetadata()->parameters(__NAMESPACE__.'\\themedButton');

        self::assertSame(['theme'], array_keys($dependencies));
        self::assertFalse($dependencies['theme']->required);
        self::assertSame(['label' => 'Save'], new PropBinder()->arguments(__NAMESPACE__.'\\themedButton', ['label' => 'Save']));
    }

    public function testTheProvidersOfAClass(): void
    {
        $component = new #[Provide] class {
            #[Provide]
            public Theme $theme = Theme::Light;
        };

        $provides = new ContextMetadata()->provides($component::class);

        self::assertSame([$component::class, Theme::class], array_map(static fn ($p): string => $p->type, $provides), 'the class first');
        self::assertSame($component, $provides[0]->valueOf($component));
        self::assertSame(Theme::Light, $provides[1]->valueOf($component));
    }

    public function testProvideOnAParentClassInherits(): void
    {
        $metadata = new ContextMetadata();

        self::assertSame([InheritedProvider::class], array_map(static fn ($p): string => $p->type, $metadata->provides(InheritedProvider::class)), 'through a parent of the parent');
        self::assertSame([ConcreteChildProvider::class], array_map(static fn ($p): string => $p->type, $metadata->provides(ConcreteChildProvider::class)), 'a child of a concrete provider');
        self::assertSame([], $metadata->provides(PlainProvider::class), 'no #[Provide] on the class or a parent');
    }

    /** @return iterable<string, array{object, string}> */
    public static function invalidComponents(): iterable
    {
        yield 'a scalar' => [new class {
            #[Context]
            public string $locale = '';
        }, 'has the type "string", but the key of a context is one class, interface or enum'];
        yield 'a closure' => [new class {
            #[Provide]
            public ?\Closure $save = null;
        }, 'has the type "?Closure"'];
        yield 'a union' => [new class {
            #[Context]
            public Theme|\Countable|null $value = null;
        }, 'but the key of a context'];
        yield 'a readonly property' => [new class {
            #[Context]
            public readonly Theme $theme;

            public function __construct()
            {
                $this->theme = Theme::Light;
            }
        }, 'must not be static, readonly, promoted, a prop or state'];
        yield 'a prop' => [new class {
            #[Context, Prop]
            public ?Theme $theme = null;
        }, 'must not be static, readonly, promoted, a prop or state'];
    }

    #[DataProvider('invalidComponents')]
    public function testTheKeyOfAContextIsOneNamedType(object $component, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $metadata = new ContextMetadata();
        $metadata->properties($component::class);
        $metadata->provides($component::class);
    }
}

function themedButton(string $label, #[Context] Theme $theme = Theme::Light): string
{
    return $label.$theme->value;
}

#[Provide]
abstract class AbstractProvider
{
}

abstract class MiddleProvider extends AbstractProvider
{
}

final class InheritedProvider extends MiddleProvider
{
}

#[Provide]
class ConcreteProvider
{
}

final class ConcreteChildProvider extends ConcreteProvider
{
}

final class PlainProvider
{
}
