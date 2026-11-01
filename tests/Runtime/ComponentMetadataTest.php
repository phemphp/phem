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

use Phem\Attribute\Component;
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\State;
use Phem\Binding\PropError;
use Phem\Metadata\ComponentMetadata;
use PHPUnit\Framework\TestCase;

final class ComponentMetadataTest extends TestCase
{
    public function testPropsAreThePropAndPathProperties(): void
    {
        $component = new class {
            #[Prop] public string $label = '';
            #[Path] public int $id = 0;
            #[State] public int $count = 0;
            public int $max = 3;

            public function __construct(public string $service = '')
            {
            }
        };

        self::assertSame(['label', 'id'], array_keys((new ComponentMetadata())->props($component::class)));
    }

    public function testReplacesIsTheComponentInTheAttribute(): void
    {
        $metadata = new ComponentMetadata();

        self::assertSame('Acme\\Ui\\Button', $metadata->replaces((new #[Component(replaces: 'Acme\\Ui\\Button')] class {})::class));
        self::assertSame('Acme\\Ui\\Button', $metadata->replaces((new #[Component('\\Acme\\Ui\\Button')] class {})::class), 'a positional argument, with a leading backslash');
        self::assertNull($metadata->replaces((new #[Component(replaces: null)] class {})::class));
        self::assertNull($metadata->replaces((new #[Component] class {})::class));
        self::assertNull($metadata->replaces((new class {})::class), 'not a component');
        self::assertNull($metadata->replaces('App\\Missing'));
    }

    public function testAnAbstractClassIsNotAComponent(): void
    {
        $metadata = new ComponentMetadata();

        self::assertFalse($metadata->isComponent(AbstractComponent::class), 'its children declare #[Component]');
        self::assertTrue($metadata->isComponent(ConcreteComponent::class));
    }

    public function testPrivatePropIsAnError(): void
    {
        $component = new class {
            #[Prop] private string $label = ''; // @phpstan-ignore property.onlyWritten (the test needs a private prop)
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The #[Prop] property "label" of <');
        $this->expectExceptionMessage('must be a prop: public, not static, not promoted, and not #[State].');

        (new ComponentMetadata())->props($component::class);
    }

    public function testStatePropIsAnError(): void
    {
        $component = new class {
            #[Prop, State] public int $count = 0;
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('must be a prop');

        (new ComponentMetadata())->props($component::class);
    }

    public function testTheRestPropIsNotInTheProps(): void
    {
        $component = new class {
            #[Prop] public string $label = '';
            /** @var array<string, mixed> */
            #[Prop(rest: true)] public array $attrs = [];
        };

        $metadata = new ComponentMetadata();
        self::assertSame(['label'], array_keys($metadata->props($component::class)));
        self::assertSame('attrs', $metadata->restProp($component::class)?->getName());
        self::assertNull($metadata->restProp(\get_class(new class {
            #[Prop] public string $label = '';
        })));
    }

    public function testTwoRestPropsAreAnError(): void
    {
        $component = new class {
            /** @var array<string, mixed> */
            #[Prop(rest: true)] public array $a = [];
            /** @var array<string, mixed> */
            #[Prop(rest: true)] public array $b = [];
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('has two rest props, "a" and "b". A class component has at most one.');

        (new ComponentMetadata())->restProp($component::class);
    }

    public function testARestPropThatCannotGetAnArrayIsAnError(): void
    {
        $component = new class {
            #[Prop(rest: true)] public string $attrs = '';
        };

        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The rest prop "attrs" of <');
        $this->expectExceptionMessage('gets a hash of props, so its type must accept an array.');

        (new ComponentMetadata())->props($component::class);
    }
}

#[Component]
abstract class AbstractComponent
{
}

#[Component]
final class ConcreteComponent extends AbstractComponent
{
}
