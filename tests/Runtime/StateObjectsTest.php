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

use Phem\Attribute\State;
use Phem\Handler\ObjectIdentifier;
use Phem\Metadata\ComponentMetadata;
use Phem\State\StateAccessor;
use Phem\State\StateObjects;
use Phem\State\StateValidator;
use Phem\State\Type\BackedEnumType;
use Phem\State\Type\ObjectType;
use Phem\State\Type\StateValueType;
use Phem\Tests\Fixtures\PetDraft;
use Phem\Tests\Fixtures\PetHome;
use Phem\Tests\Fixtures\Size;
use Phem\Validation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation as SymfonyValidation;

final class StateObjectsTest extends TestCase
{
    public function testAnObjectOfAClassIsAStateObject(): void
    {
        $types = new \ReflectionClass(TypedState::class);

        self::assertSame(PetDraft::class, StateObjects::classOf($types->getProperty('draft')->getType()));
        self::assertSame(\DateTimeInterface::class, StateObjects::classOf($types->getProperty('date')->getType()), 'an interface');
        self::assertNull(StateObjects::classOf($types->getProperty('size')->getType()), 'an enum');
        self::assertNull(StateObjects::classOf($types->getProperty('validation')->getType()), 'a StateValue');
        self::assertNull(StateObjects::classOf($types->getProperty('name')->getType()));
        self::assertNull(StateObjects::classOf($types->getProperty('any')->getType()));
        self::assertSame(['draft', 'date'], array_map(static fn (array $o): string => $o[0]->getName(), new ComponentMetadata()->stateObjects(TypedState::class)));
    }

    public function testTheStateRestoresAnObject(): void
    {
        $state = self::accessor();
        $component = new TypedState();
        $component->draft->name = 'Bella';
        $component->draft->size = Size::Large;
        $component->draft->born = new \DateTimeImmutable('2022-01-31T00:00:00+00:00');
        $component->draft->home->city = 'Bergen';

        $exported = $state->export($component);
        self::assertSame(['name' => 'Bella', 'age' => null, 'size' => 'l', 'born' => '2022-01-31T00:00:00+00:00', 'home' => ['city' => 'Bergen'], 'kind' => 'dog'], $exported['draft']);
        self::assertSame('2026-11-08T10:00:00+00:00', $exported['date']);

        $copy = new TypedState();
        $copy->date = null;
        $state->restore($copy, json_decode(json_encode($exported, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));

        self::assertEquals($component->draft, $copy->draft);
        self::assertNotSame($component->draft, $copy->draft);
        self::assertEquals($component->date, $copy->date);
    }

    public function testTheStateKeepsOnlyThePublicProperties(): void
    {
        $state = new StateObjects(self::serializer())->toState(new PetDraft(), TypedState::class, 'draft');

        self::assertIsArray($state);
        self::assertArrayNotHasKey('label', $state, 'a getter');
        self::assertArrayNotHasKey('adult', $state, 'an isser');
        self::assertSame(['city' => 'Oslo'], $state['home']);
    }

    public function testAnObjectInAPropertyWithNoClassTypeIsKeptAsItIs(): void
    {
        $component = new UnionState();

        self::assertSame($component->draft, self::accessor()->export($component)['draft'], 'json_encode() writes it, as before: the restore gives the array');
    }

    public function testANestedEntityIsNotAStateObject(): void
    {
        $objects = new StateObjects(self::serializer(), new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return $value instanceof PetHome ? ['id' => 3] : null;
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('<TypedState> keeps the Doctrine entity Phem\Tests\Fixtures\PetHome in #[State] $draft.home.');

        $objects->toState(new PetDraft(), TypedState::class, 'draft');
    }

    public function testAnEntityIsNotAStateObject(): void
    {
        $objects = new StateObjects(self::serializer(), new class implements ObjectIdentifier {
            public function identify(object $value): array
            {
                return ['id' => 3];
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('<TypedState> keeps the Doctrine entity Phem\Tests\Fixtures\PetDraft in #[State] $draft. Keep a draft class in the state, and the id of the entity.');

        $objects->toState(new PetDraft(), TypedState::class, 'draft');
    }

    public function testAStateObjectNeedsTheSerializer(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A #[State] object needs the Symfony Serializer: install symfony/serializer.');

        new StateAccessor()->export(new TypedState());
    }

    public function testAChangeInPlaceValidatesAgain(): void
    {
        $state = self::accessor();
        $validator = new StateValidator(SymfonyValidation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), new ComponentMetadata(), $state);
        $form = new DraftForm();
        $state->initialize($form);

        $validator->touch($form, ['draft.name' => true]);
        $validator->settle($form);
        self::assertSame([], $form->validation->errors());

        $form->draft->name = '';
        $validator->settle($form);
        self::assertSame(['draft.name' => 'Give a name.'], $form->validation->errors(), 'the same object, with another JSON value');
    }

    private static function accessor(): StateAccessor
    {
        return new StateAccessor(new ComponentMetadata(), [new StateValueType(), new BackedEnumType(), new ObjectType(new StateObjects(self::serializer()))]);
    }

    private static function serializer(): Serializer
    {
        return new Serializer([new BackedEnumNormalizer(), new DateTimeNormalizer(), new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())]);
    }
}

final class TypedState
{
    #[State] public PetDraft $draft;
    #[State] public ?\DateTimeInterface $date;
    #[State] public Size $size = Size::Small;
    #[State] public Validation $validation;
    #[State] public string $name = '';
    #[State] public mixed $any = null;
    public function __construct()
    {
        $this->draft = new PetDraft();
        $this->date = new \DateTimeImmutable('2026-11-08T10:00:00+00:00');
        $this->validation = new Validation();
    }
}

final class DraftForm
{
    #[State, Assert\Valid] public PetDraft $draft;
    #[State] public Validation $validation;
    public function __construct()
    {
        $this->draft = new PetDraft();
    }
}

final class UnionState
{
    #[State] public PetDraft|PetHome $draft;
    public function __construct()
    {
        $this->draft = new PetHome();
    }
}
