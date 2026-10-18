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

use Mamu\Attribute\Path;
use Mamu\Attribute\Prop;
use Mamu\Attribute\State;
use Mamu\Binding\PropError;
use Mamu\Metadata\ComponentMetadata;
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

    public function testPrivatePropIsAnError(): void
    {
        $component = new class {
            #[Prop] private string $label = '';
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
}
