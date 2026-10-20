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
use Phem\Attribute\Provide;
use Phem\Binding\ContextError;
use Phem\Rendering\ContextResolver;
use Phem\Rendering\Fiber;
use Phem\Tests\Fixtures\Locale;
use Phem\Tests\Fixtures\Theme;
use Phem\Tests\Fixtures\ThemeProvider;
use PHPUnit\Framework\TestCase;

final class ContextResolverTest extends TestCase
{
    public function testTheNearestProviderOfTheTypeGivesTheContext(): void
    {
        $outer = self::fiber('outer', new ThemeProvider(Theme::Light));
        $inner = self::fiber('inner', new ThemeProvider(Theme::Dark), $outer);
        $middle = self::fiber('middle', new \stdClass(), $inner);
        $consumer = self::fiber('consumer', $component = new class {
            #[Context]
            public Theme $theme;
        }, $middle);

        new ContextResolver()->assign($component, $consumer);

        self::assertSame(Theme::Dark, $component->theme);
        self::assertSame([Theme::class => 'inner'], $consumer->contexts, 'the fiber is a consumer of the provider');
    }

    public function testAnInterfaceIsAKey(): void
    {
        $provider = self::fiber('provider', $list = new #[Provide] class implements \Countable {
            public function count(): int
            {
                return 3;
            }
        });
        $consumer = self::fiber('consumer', $component = new class {
            #[Context]
            public \Countable $items;
        }, $provider);

        new ContextResolver()->assign($component, $consumer);

        self::assertSame($list, $component->items, 'a provider of the class provides itself');
    }

    public function testAnOptionalContextWithNoProviderGetsItsDefault(): void
    {
        $consumer = self::fiber('consumer', $component = new class {
            #[Context]
            public Theme $theme = Theme::Dark;
            #[Context]
            public ?\Countable $items = null;
        });

        new ContextResolver()->assign($component, $consumer);

        self::assertSame(Theme::Dark, $component->theme);
        self::assertNull($component->items);
        self::assertSame([], $consumer->contexts, 'not a consumer: no context can change');
    }

    public function testARequiredContextWithNoProviderThrows(): void
    {
        $page = self::fiber('page', new \stdClass(), null, 'App\Pages\Settings');
        $consumer = self::fiber('consumer', $component = new class {
            #[Context]
            public Theme $theme;
        }, $page, 'App\Components\Chart');

        $this->expectException(ContextError::class);
        $this->expectExceptionMessage('<Chart> needs a "Phem\Tests\Fixtures\Theme" context for "theme", but no component above it provides one. Render <Chart> inside a component with #[Provide] for this type, or give "theme" a default value. Path: <Settings> > <Chart>.');

        new ContextResolver()->assign($component, $consumer);
    }

    public function testAProviderChangesItsContextWhenTheValueIsAnother(): void
    {
        $resolver = new ContextResolver();
        $component = new ThemeProvider(Theme::Light);
        $fiber = self::fiber('provider', $component);

        $fiber->pass = 1;
        $resolver->provide($fiber);
        self::assertTrue($fiber->changesContext, 'with no value in the pass before');

        $fiber->pass = 2;
        $resolver->provide($fiber);
        self::assertFalse($fiber->changesContext, 'the same enum');

        $component->theme = Theme::Dark;
        $fiber->pass = 3;
        $resolver->provide($fiber);
        self::assertTrue($fiber->changesContext, 'another enum');

        $fiber->pass = 5;
        $resolver->provide($fiber);
        self::assertTrue($fiber->changesContext, 'the fiber did not provide in the pass before');
    }

    public function testAReadonlyObjectIsComparedByValueAndAMutableObjectAlwaysChanges(): void
    {
        $resolver = new ContextResolver();
        $component = new class {
            #[Provide]
            public Locale $locale;
            /** @var \ArrayObject<int, int> */
            #[Provide]
            public \ArrayObject $data;
        };
        $component->locale = new Locale('es');
        $component->data = new \ArrayObject();
        $fiber = self::fiber('provider', $component);
        $fiber->pass = 1;
        $resolver->provide($fiber);

        $readonly = new class {
            #[Provide]
            public Locale $locale;
        };
        $readonly->locale = new Locale('es');
        $only = self::fiber('readonly', $readonly);
        $only->pass = 1;
        $resolver->provide($only);
        $readonly->locale = new Locale('es');
        $only->pass = 2;
        $resolver->provide($only);
        self::assertFalse($only->changesContext, 'another readonly object with the same values');
        $readonly->locale = new Locale('fr');
        $only->pass = 3;
        $resolver->provide($only);
        self::assertTrue($only->changesContext, 'a readonly object with other values');

        $fiber->pass = 2;
        $resolver->provide($fiber);
        self::assertTrue($fiber->changesContext, 'the same mutable object: a handler can change it in place');

        $self = self::fiber('self', new #[Provide] class {});
        $self->pass = 1;
        $resolver->provide($self);
        $self->pass = 2;
        $resolver->provide($self);
        self::assertTrue($self->changesContext, 'a provider of itself changes each time that it renders');
    }

    private static function fiber(string $id, object $instance, ?Fiber $parent = null, string $fqcn = ''): Fiber
    {
        $fiber = new Fiber($id, '/'.$id, '' !== $fqcn ? $fqcn : $instance::class);
        $fiber->instance = $instance;
        $fiber->parent = $parent;

        return $fiber;
    }
}
