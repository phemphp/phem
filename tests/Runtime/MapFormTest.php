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

use Phem\Argument\CallableArguments;
use Phem\Argument\MapFormValueResolver;
use Phem\Attribute\MapForm;
use Phem\Attribute\State;
use Phem\Attribute\Transactional;
use Phem\Event;
use Phem\Form\FormMapper;
use Phem\Form\InvalidForm;
use Phem\Handler\HandlerAttributes;
use Phem\Handler\HandlerCall;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\HandlerMiddleware;
use Phem\Handler\MapFormMiddleware;
use Phem\Handler\TransactionalMiddleware;
use Phem\Handler\TransactionRunner;
use Phem\State\StateValidator;
use Phem\Tests\Fixtures\ClosedDates;
use Phem\Tests\Fixtures\Size;
use Phem\Validation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation as SymfonyValidation;

/** The map of the form of an event to a #[MapForm] parameter (see Phem\Attribute\MapForm). */
final class MapFormTest extends TestCase
{
    public function testTheFieldsGetTheTypesOfTheProperties(): void
    {
        $dates = self::mapper()->map(new Event('submit', form: ['from' => '2026-12-24', 'to' => '', 'size' => 'l', 'guests' => '3', 'notify' => '1', 'other' => 'x']), 'dates', ClosedDates::class, new MapForm());

        self::assertInstanceOf(ClosedDates::class, $dates);
        self::assertSame('2026-12-24', $dates->from?->format('Y-m-d'));
        // An empty string is null for a nullable property.
        self::assertNull($dates->to);
        self::assertSame(Size::Large, $dates->size);
        self::assertSame(3, $dates->guests);
        self::assertTrue($dates->notify);
    }

    public function testANameMapsTheFieldsWithItsPrefix(): void
    {
        $dates = self::mapper()->map(new Event('submit', form: ['dates[from]' => '2026-12-24', 'from' => '2000-01-01']), 'dates', ClosedDates::class, new MapForm(name: 'dates'));

        self::assertInstanceOf(ClosedDates::class, $dates);
        self::assertSame('2026-12-24', $dates->from?->format('Y-m-d'));
    }

    public function testTheErrorsHaveThePathsOfTheFields(): void
    {
        try {
            self::mapper()->map(new Event('submit', form: ['line[qty]' => '0']), 'order', MappedOrder::class, new MapForm());
            self::fail('The form is valid.');
        } catch (InvalidForm $e) {
            self::assertSame(['line.qty' => 'This value should be positive.'], $e->errors);
        }

        try {
            self::mapper()->map(new Event('submit', form: ['dates[guests]' => 'many', 'dates[from]' => 'never']), 'dates', ClosedDates::class, new MapForm(name: 'dates'));
            self::fail('The form is valid.');
        } catch (InvalidForm $e) {
            // A value that does not fit the type, and the constraints of the other fields.
            self::assertSame(['dates.guests' => 'This value is not valid.', 'dates.from' => 'This value is not valid.'], $e->errors);
        }
    }

    public function testTheValidationGroups(): void
    {
        $mapper = self::mapper();
        self::assertInstanceOf(MappedNote::class, $mapper->map(new Event('submit', form: ['note' => '']), 'note', MappedNote::class, new MapForm()));

        $this->expectException(InvalidForm::class);
        $mapper->map(new Event('submit', form: ['note' => '']), 'note', MappedNote::class, new MapForm(validationGroups: ['strict']));
    }

    public function testTheResolverGivesTheObjectThatTheMiddlewareMapped(): void
    {
        $mapper = self::mapper();
        $event = new Event('submit', form: ['from' => '2026-12-24']);
        $mapped = $mapper->map($event, 'dates', ClosedDates::class, new MapForm());

        $request = new Request(attributes: [CallableArguments::EVENT => $event]);
        $argument = new ArgumentMetadata('dates', ClosedDates::class, false, false, null, attributes: [new MapForm()]);

        self::assertSame([$mapped], [...new MapFormValueResolver($mapper)->resolve($request, $argument)]);
        self::assertSame([], [...new MapFormValueResolver($mapper)->resolve($request, new ArgumentMetadata('dates', ClosedDates::class, false, false, null))]);
    }

    public function testTheResolverNeedsAnEvent(): void
    {
        $this->expectExceptionMessage('The #[MapForm] parameter $dates maps the form of an event: use it in an event handler.');

        [...new MapFormValueResolver(self::mapper())->resolve(new Request(), new ArgumentMetadata('dates', ClosedDates::class, false, false, null, attributes: [new MapForm()]))];
    }

    public function testAMapNeedsTheSerializer(): void
    {
        $this->expectExceptionMessage('#[MapForm] needs the Symfony Serializer: install symfony/serializer.');

        new FormMapper()->map(new Event('submit'), 'dates', ClosedDates::class, new MapForm());
    }

    public function testAMapNeedsAClass(): void
    {
        $this->expectExceptionMessage('The #[MapForm] parameter $dates needs the type of a class, "array" given.');

        self::mapper()->map(new Event('submit'), 'dates', 'array', new MapForm());
    }

    public function testAValidFormRunsTheHandlerWithTheObject(): void
    {
        $component = new MappedComponent();

        self::invoker()->invoke($component->add(...), new Event('submit', form: ['from' => '2026-12-24']));

        self::assertSame('2026-12-24', $component->saved);
    }

    public function testAnInvalidFormStopsTheHandlerAndShowsTheErrors(): void
    {
        $component = new MappedComponent();
        $transactions = new CountingTransactions();

        self::invoker(new TransactionalMiddleware($transactions))->invoke($component->add(...), new Event('submit', form: ['from' => '', 'guests' => '0']));

        self::assertSame('', $component->saved);
        self::assertSame(['from' => 'Choose the first day.', 'guests' => 'This value should be positive.'], $component->validation->errors());
        self::assertSame(['form' => ['from' => 'Choose the first day.', 'guests' => 'This value should be positive.']], $component->validation->toState());
        // Before the transaction.
        self::assertSame(0, $transactions->runs);
    }

    public function testTheValidationAfterTheHandlersKeepsTheErrors(): void
    {
        $component = new MappedComponent();
        $validator = new StateValidator(SymfonyValidation::createValidator());

        self::invoker(validator: $validator)->invoke($component->add(...), new Event('submit', form: ['from' => '']));
        $validator->settle($component);

        self::assertSame(['from' => 'Choose the first day.'], $component->validation->errors());
    }

    public function testAMapReplacesTheErrorsOfTheLastMap(): void
    {
        $component = new MappedComponent();

        self::invoker()->invoke($component->add(...), new Event('submit', form: ['from' => '']));
        self::invoker()->invoke($component->add(...), new Event('submit', form: ['from' => '2026-12-24', 'guests' => '0']));

        // The first day is valid now: its error of the first submit is gone.
        self::assertSame(['guests' => 'This value should be positive.'], $component->validation->errors());
    }

    public function testAValidFormRemovesTheErrorsOfTheLastMap(): void
    {
        $component = new MappedComponent();
        $component->validation = new Validation(['email'], null, ['email' => 'Too short.']);

        self::invoker()->invoke($component->add(...), new Event('submit', form: ['from' => '']));
        self::assertSame(['email' => 'Too short.', 'from' => 'Choose the first day.'], $component->validation->errors());
        self::assertFalse($component->validation->valid());

        // No validation of the #[State] properties runs after it: the map removes the errors.
        self::invoker()->invoke($component->add(...), new Event('submit', form: ['from' => '2026-12-24']));
        self::assertSame(['email' => 'Too short.'], $component->validation->errors());
        self::assertSame('2026-12-24', $component->saved);
    }

    public function testAFormErrorDoesNotStopAValidatedHandler(): void
    {
        $component = new MappedComponent();
        $component->validation = new Validation(form: ['from' => 'Choose the first day.']);

        self::assertTrue(new StateValidator(SymfonyValidation::createValidator())->submit($component, []));
    }

    public function testTheContextCanNotTurnOffTheErrors(): void
    {
        $mapper = self::mapper();

        try {
            $mapper->map(new Event('submit', form: ['from' => '2026-12-24', 'guests' => 'many']), 'dates', ClosedDates::class, new MapForm(serializationContext: ['collect_denormalization_errors' => false]));
            self::fail('The form is valid.');
        } catch (InvalidForm $e) {
            self::assertSame(['guests' => 'This value is not valid.'], $e->errors);
        }

        try {
            $mapper->map(new Event('submit', form: ['from' => '2026-12-24', 'admin' => '1']), 'dates', ClosedDates::class, new MapForm(serializationContext: ['allow_extra_attributes' => false]));
            self::fail('The form is valid.');
        } catch (InvalidForm $e) {
            self::assertSame(['admin' => 'This value is not valid.'], $e->errors);
        }
    }

    public function testACallInTheMarkupCanNotGiveAMapFormParameter(): void
    {
        $component = new MappedComponent();

        // A call of the other parameters is fine.
        self::assertInstanceOf(\Closure::class, HandlerCall::bind($component->addFor(...), ['front']));

        $this->expectExceptionMessage('The parameter $dates of the handler "add()" has #[MapForm]: the form gives its value, so the call in the markup can\'t give it an argument.');

        HandlerCall::bind($component->add(...), [new ClosedDates()]);
    }

    public function testTheMapFormParametersOfAHandler(): void
    {
        $attributes = new HandlerAttributes();
        $component = new MappedComponent();

        $parameters = $attributes->ofParameters($component->addFor(...), MapForm::class);
        self::assertSame(['dates'], array_keys($parameters));
        self::assertSame(ClosedDates::class, $parameters['dates'][1]);
        // The parameters of a method stay for the life of the service.
        self::assertSame($parameters, $attributes->ofParameters(new MappedComponent()->addFor(...), MapForm::class));

        self::assertSame([], $attributes->ofParameters(static fn (Event $e) => null, MapForm::class));
    }

    public function testWithoutAValidationPropertyTheFirstErrorIsAToast(): void
    {
        $event = new Event('submit', form: ['guests' => '0']);
        $called = false;

        self::invoker()->invoke(static function (#[MapForm] ClosedDates $dates) use (&$called): void { $called = true; }, $event);

        self::assertFalse($called);
        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Choose the first day.', 'type' => 'error']]], $event->dispatcher->dispatched());
    }

    public function testTheToastTakesTheFirstErrorWithAMessage(): void
    {
        $event = new Event('submit', form: ['code' => '', 'name' => '']);

        self::invoker()->invoke(static function (#[MapForm] SilentForm $form): void {}, $event);

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Give a name.', 'type' => 'error']]], $event->dispatcher->dispatched());
    }

    public function testErrorsWithNoMessageShowNoToast(): void
    {
        $event = new Event('submit', form: ['code' => '']);
        $called = false;

        self::invoker()->invoke(static function (#[MapForm] SilentForm $form) use (&$called): void { $called = true; }, $event);

        self::assertFalse($called);
        self::assertSame([], $event->dispatcher->dispatched());
    }

    private static function mapper(): FormMapper
    {
        $types = new PropertyInfoExtractor([], [new ReflectionExtractor()]);
        $serializer = new Serializer([new BackedEnumNormalizer(), new DateTimeNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()), propertyTypeExtractor: $types)]);

        return new FormMapper($serializer, SymfonyValidation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    /** The map_form middleware and the resolver with the same mapper, as the services. */
    private static function invoker(?HandlerMiddleware $inner = null, StateValidator $validator = new StateValidator()): HandlerInvoker
    {
        $mapper = self::mapper();
        $resolver = new ArgumentResolver(null, [new MapFormValueResolver($mapper), ...ArgumentResolver::getDefaultArgumentValueResolvers()]);

        return new HandlerInvoker(array_filter([new MapFormMiddleware($mapper, $validator), $inner]), new CallableArguments($resolver));
    }
}

#[Transactional]
final class MappedComponent
{
    #[State] public Validation $validation;
    #[State] public string $saved = '';
    public function __construct()
    {
        $this->validation = new Validation();
    }

    public function add(#[MapForm] ClosedDates $dates): void
    {
        $this->saved = (string) $dates->from?->format('Y-m-d');
    }

    public function addFor(string $side, #[MapForm] ClosedDates $dates): void
    {
        $this->saved = $side.' '.$dates->from?->format('Y-m-d');
    }
}

final class MappedOrder
{
    #[Assert\Valid]
    public ?MappedLine $line = null;
}

final class MappedLine
{
    #[Assert\Positive]
    public int $qty = 1;
}

final class MappedNote
{
    #[Assert\NotBlank(groups: ['strict'])]
    public string $note = '';
}

final class CountingTransactions implements TransactionRunner
{
    public int $runs = 0;

    public function run(\Closure $callback): mixed
    {
        ++$this->runs;

        return $callback();
    }
}

/** A form whose first constraint has no message. */
final class SilentForm
{
    #[Assert\NotBlank(message: ' ')]
    public string $code = '';

    #[Assert\NotBlank(message: 'Give a name.')]
    public string $name = 'x';
}
