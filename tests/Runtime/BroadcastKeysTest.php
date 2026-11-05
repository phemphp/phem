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

use Phem\Attribute\Broadcast;
use Phem\Attribute\Component;
use Phem\Handler\ObjectIdentifier;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Fiber;
use Phem\Stream\BroadcastKeys;
use Phem\Tests\Fixtures\BroadcastHandlerOfAPage;
use Phem\Tests\Fixtures\Broadcasting;
use Phem\Tests\Fixtures\BroadcastingPageWithAHandler;
use Phem\Tests\Fixtures\BroadcastingComponent;
use Phem\Tests\Fixtures\HandlerWithHandlersFalse;
use Phem\Tests\Fixtures\KeyedBroadcastingComponent;
use Phem\Tests\Fixtures\KeyedBroadcastingReplacement;
use Phem\Tests\Fixtures\KeyOnPage;
use Phem\Tests\Fixtures\KeyOnState;
use Phem\Tests\Fixtures\KeyWithoutBroadcast;
use Phem\Tests\Fixtures\ReceivingComponentWithAHandler;
use Phem\Tests\Fixtures\ReceivingPage;
use Phem\Tests\Fixtures\ReceivingPlainClass;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Size;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__).'/Fixtures/InvalidBroadcasts.php';
require_once \dirname(__DIR__).'/Fixtures/KeyedBroadcastingFunctions.php';

final class BroadcastKeysTest extends TestCase
{
    public function testAFiberHasTheKeyOfItsComponentAndOfItsInstance(): void
    {
        $keys = self::keys();
        $fiber = self::fiber(new Scientist(42, 'Ada'), Size::Large);

        self::assertSame([
            $keys->ofComponent(KeyedBroadcastingComponent::class),
            $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Grace'), 'size' => Size::Large]),
        ], $keys->ofFiber($fiber));
    }

    public function testAReplacementHasTheKeysOfTheComponentThatItReplaces(): void
    {
        $keys = self::keys();
        $replacement = new KeyedBroadcastingReplacement();
        $replacement->scientist = new Scientist(42, 'Ada');
        $replacement->size = Size::Large;
        $fiber = new Fiber('phem0123456789ab', '/c', KeyedBroadcastingReplacement::class);
        $fiber->instance = $replacement;

        self::assertSame([
            $keys->ofComponent(KeyedBroadcastingComponent::class),
            $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Grace'), 'size' => Size::Large]),
        ], $keys->ofFiber($fiber));
    }

    public function testAFunctionReplacementWithARestPropHasTheDefaultsOfTheOriginal(): void
    {
        $keys = self::keys();
        $fiber = new Fiber('phem0123456789ab', '/c', 'Phem\\Tests\\Fixtures\\KeyedBroadcastingFunction');
        $fiber->props = ['scientist' => new Scientist(42, 'Ada')];

        self::assertSame([
            $keys->ofComponent(KeyedBroadcastingComponent::class),
            $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Ada'), 'size' => Size::Small]),
        ], $keys->ofFiber($fiber));
    }

    public function testAReplacementOfAFunctionHasTheDefaultsOfTheFunction(): void
    {
        $keys = self::keys();
        $original = 'Phem\\Tests\\Fixtures\\KeyedBroadcastingOriginal';
        $fiber = new Fiber('phem0123456789ab', '/c', 'Phem\\Tests\\Fixtures\\KeyedBroadcastingWrapper');

        self::assertSame([$keys->ofComponent($original), $keys->ofInstance($original, ['size' => Size::Small])], $keys->ofFiber($fiber), 'the default value of the parameter');

        $fiber->props = ['size' => Size::Large];
        self::assertSame([$keys->ofComponent($original), $keys->ofInstance($original, ['size' => Size::Large])], $keys->ofFiber($fiber), 'the value that the parent gave');
    }

    public function testTheKeyOfAnInstanceIsAnHmacOfTheValuesOfItsKeyProps(): void
    {
        $keys = self::keys();
        $key = $keys->ofInstance(KeyedBroadcastingComponent::class, ['size' => 'l', 'scientist' => new Scientist(42, 'Ada')]);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $key);
        // The order of the values, and an enum or its value, give the same key.
        self::assertSame($key, $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Ada'), 'size' => Size::Large]));
        self::assertNotSame($key, $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(7, 'Ada'), 'size' => Size::Large]));
        self::assertNotSame($key, new BroadcastKeys('other', self::identifier())->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Ada'), 'size' => Size::Large]), 'another secret');
        self::assertNotSame($key, $keys->ofComponent(KeyedBroadcastingComponent::class));
    }

    public function testAnEntityIsItsIdentifierNotItsClassOrItsString(): void
    {
        $keys = self::keys();
        $key = $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Ada'), 'size' => Size::Large]);

        // A Doctrine proxy has another class.
        $proxy = new class {
            public int $id = 42;
        };
        // A title can change, its identifier does not.
        $titled = new class implements \Stringable {
            public int $id = 42;

            public function __toString(): string
            {
                return 'Grace';
            }
        };

        self::assertSame($key, $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => $proxy, 'size' => Size::Large]));
        self::assertSame($key, $keys->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => $titled, 'size' => Size::Large]));
    }

    public function testAComponentWithNoKeyHasTheKeyOfTheComponentOnly(): void
    {
        $keys = self::keys();
        $fiber = new Fiber('phem0123456789ab', '/c', BroadcastingComponent::class);

        self::assertSame([$keys->ofComponent(BroadcastingComponent::class)], $keys->ofFiber($fiber));
    }

    public function testAComponentWithNoBroadcastHasNoKeys(): void
    {
        self::assertSame([], self::keys()->ofFiber(new Fiber('phem0123456789ab', '/c', Scientist::class)));
    }

    public function testAPageHasNoKeys(): void
    {
        self::assertNull(self::keys()->props(Broadcasting::class));
    }

    public function testTheKeyOfAnInstanceNeedsAllTheKeyProps(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs the values of all its key props, but "size" is missing');

        self::keys()->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new Scientist(42, 'Ada')]);
    }

    public function testAKeyPropIsNotAnObjectWithNoIdentifier(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The key prop "scientist" of the component "'.KeyedBroadcastingComponent::class.'" is a "stdClass"');

        self::keys()->ofInstance(KeyedBroadcastingComponent::class, ['scientist' => new \stdClass(), 'size' => Size::Small]);
    }

    public function testAFunctionComponentHasTheKeysOfItsParameters(): void
    {
        $keys = self::keys();
        $fiber = new Fiber('phem0123456789ab', '/c', 'Phem\Tests\Fixtures\KeyedWidget');
        $fiber->props = ['id' => 7, 'label' => 'Not a key prop'];

        self::assertSame(['id', 'locale'], $keys->props('Phem\Tests\Fixtures\KeyedWidget'));
        // The parent did not give the locale: its default value, as the streams give it.
        self::assertSame([$keys->ofComponent('Phem\Tests\Fixtures\KeyedWidget'), $keys->ofInstance('Phem\Tests\Fixtures\KeyedWidget', ['id' => '7', 'locale' => 'en'])], $keys->ofFiber($fiber));
    }

    public function testAKeyNeedsBroadcastOnItsComponent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The prop "id" of "'.KeyWithoutBroadcast::class.'" has #[Phem\Attribute\BroadcastKey], but only a component with #[Phem\Attribute\Broadcast] has keys');

        self::keys()->props(KeyWithoutBroadcast::class);
    }

    public function testAKeyIsOnAProp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The property "id" of the component "'.KeyOnState::class.'" has #[Phem\Attribute\BroadcastKey], but it is not a prop');

        self::keys()->props(KeyOnState::class);
    }

    public function testAPageHasNoKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('but only a component with #[Phem\Attribute\Broadcast] has keys');

        self::keys()->props(KeyOnPage::class);
    }

    public function testAComponentWithBroadcastOnAHandlerBroadcasts(): void
    {
        $metadata = new ComponentMetadata();
        $component = new #[Component] class {
            #[Broadcast]
            public function like(): void
            {
            }

            public function toggle(): void
            {
            }
        };

        self::assertTrue($metadata->broadcasts($component::class));
        self::assertSame(['like'], $metadata->broadcastHandlers($component::class));
    }

    public function testAHandlerOfAPageWithBroadcastHasNoBroadcast(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The method "'.BroadcastingPageWithAHandler::class.'::save()" has #[Broadcast]');

        new ComponentMetadata()->broadcasts(BroadcastingPageWithAHandler::class);
    }

    public function testAHandlerOfAPageHasNoBroadcast(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The method "'.BroadcastHandlerOfAPage::class.'::save()" has #[Broadcast], but "'.BroadcastHandlerOfAPage::class.'" is not a component');

        new ComponentMetadata()->broadcasts(BroadcastHandlerOfAPage::class);
    }

    public function testAComponentWithHandlersFalseBroadcastsAndItsHandlersDoNotRefreshIt(): void
    {
        $metadata = new ComponentMetadata();
        $receiving = new #[Component, Broadcast(handlers: false)] class {
            public function save(): void
            {
            }
        };
        $refreshing = new #[Component, Broadcast] class {
        };

        self::assertTrue($metadata->broadcasts($receiving::class));
        self::assertTrue($metadata->receivesOnly($receiving::class));
        self::assertFalse($metadata->receivesOnly($refreshing::class));
        self::assertFalse($metadata->receivesOnly(KeyWithoutBroadcast::class));
    }

    public function testAComponentWithHandlersFalseHasNoHandlerWithBroadcast(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"'.ReceivingComponentWithAHandler::class.'" has #[Broadcast(handlers: false)], so no handler refreshes it, but its method "save()" has #[Broadcast].');

        new ComponentMetadata()->receivesOnly(ReceivingComponentWithAHandler::class);
    }

    public function testAPageCannotHaveHandlersFalse(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"'.ReceivingPage::class.'" has #[Broadcast(handlers: false)], but it is not a class component');

        new ComponentMetadata()->broadcasts(ReceivingPage::class);
    }

    public function testAClassWithNoComponentCannotHaveHandlersFalse(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"'.ReceivingPlainClass::class.'" has #[Broadcast(handlers: false)], but it is not a class component');

        new ComponentMetadata()->broadcasts(ReceivingPlainClass::class);
    }

    public function testAFunctionCannotHaveHandlersFalse(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Phem\Tests\Fixtures\ReceivingWidget" has #[Broadcast(handlers: false)], but it is not a class component');

        new ComponentMetadata()->broadcasts('Phem\Tests\Fixtures\ReceivingWidget');
    }

    public function testAHandlerMethodCannotHaveHandlersFalse(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The method "'.HandlerWithHandlersFalse::class.'::save()" has #[Broadcast(handlers: false)]: only a class component can have the "handlers" argument.');

        new ComponentMetadata()->broadcasts(HandlerWithHandlersFalse::class);
    }

    private static function fiber(Scientist $scientist, Size $size): Fiber
    {
        $component = new KeyedBroadcastingComponent();
        $component->scientist = $scientist;
        $component->size = $size;
        $component->label = 'Not a key prop';

        $fiber = new Fiber('phem0123456789ab', '/c', KeyedBroadcastingComponent::class);
        $fiber->instance = $component;

        return $fiber;
    }

    private static function keys(): BroadcastKeys
    {
        return new BroadcastKeys('s3cret', self::identifier());
    }

    private static function identifier(): ObjectIdentifier
    {
        return new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return property_exists($value, 'id') ? ['id' => $value->id] : null;
            }
        };
    }
}
