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
use Phem\Attribute\Validated;
use Phem\Event;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\ValidationMiddleware;
use Phem\Metadata\ComponentMetadata;
use Phem\State\StateAccessor;
use Phem\State\StateValidator;
use Phem\Validation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validation as SymfonyValidation;

/** The Validation value, its state, and the validation of the #[State] properties. */
final class StateValidatorTest extends TestCase
{
    public function testAPathTouchesItsParentAndItsChildren(): void
    {
        $validation = new Validation()->touch('address');
        self::assertTrue($validation->touched('address'));
        self::assertTrue($validation->touched('address.city'));
        self::assertFalse($validation->touched('addresses'));
        self::assertFalse($validation->touched('email'));

        // An error of the whole array shows when the user changed an item.
        $validation = new Validation()->touch('tags.0');
        self::assertTrue($validation->touched('tags'));
        self::assertFalse($validation->touched('tag'));
    }

    public function testAChangeGivesANewValidation(): void
    {
        $validation = new Validation();

        self::assertNotSame($validation, $validation->touch('email'));
        self::assertFalse($validation->touched('email'));
    }

    public function testAValidatedHandlerTouchesAllTheFields(): void
    {
        $validation = new Validation()->touch('email')->touchAll([]);

        self::assertTrue($validation->touched('password'));
        self::assertTrue($validation->touched(''));
        self::assertSame(['Default'], $validation->groups(), 'no group is the default group');
    }

    public function testTheStateKeepsOnlyTheParts(): void
    {
        self::assertSame([], new Validation()->toState());
        self::assertSame(['touched' => ['email'], 'errors' => ['email' => 'Too short.']], new Validation(['email'], null, ['email' => 'Too short.'])->toState());

        $validation = Validation::fromState(['touched' => ['email'], 'groups' => ['draft'], 'errors' => ['email' => 'Too short.']]);
        self::assertSame('Too short.', $validation->error('email'));
        self::assertSame(['draft'], $validation->groups());
    }

    public function testAStateWithAnotherFormGivesAnEmptyValidation(): void
    {
        self::assertSame([], Validation::fromState('x')->toState());
        self::assertSame(['touched' => ['email']], Validation::fromState(['touched' => ['email', 3], 'groups' => [], 'errors' => 'x'])->toState());
    }

    public function testTheStateRestoresAValidation(): void
    {
        $state = new StateAccessor();
        $form = new ValidatedForm();
        $state->initialize($form);
        $form->validation = $form->validation->touch('email');

        $exported = $state->export($form);
        self::assertSame(['touched' => ['email']], $exported['validation']);

        $copy = new ValidatedForm();
        $state->restore($copy, $exported);
        self::assertTrue($copy->validation->touched('email'));
    }

    public function testANullValidationPropertyGetsAValidation(): void
    {
        $form = new NullableValidationForm();
        new StateAccessor()->initialize($form);

        self::assertInstanceOf(Validation::class, $form->validation);
    }

    public function testTheErrorOfAPathHasTheSyntaxOfABoundField(): void
    {
        $form = new ValidatedForm();
        $validator = $this->validator();
        $validator->touch($form, ['rows.0.name' => true]);
        $validator->settle($form);

        self::assertSame(['rows.0.name' => 'This value should not be blank.'], $form->validation->errors());
    }

    public function testARejectedValueGetsAnError(): void
    {
        $form = new ValidatedForm();
        $form->email = 'ada@example.com';
        $validator = $this->validator();
        $validator->touch($form, ['email' => false]);
        $validator->settle($form);

        self::assertSame(['email' => 'This value is not valid.'], $form->validation->errors());

        // The next validation checks the value of the property again.
        $validator->touch($form, ['email' => true]);
        $validator->settle($form);
        self::assertSame([], $form->validation->errors());
    }

    public function testAnErrorOfTheClassIsTheErrorOfTheEmptyField(): void
    {
        $form = new ValidatedForm();
        $form->email = 'ada@example.com';
        $form->rows = [['name' => 'Ada']];

        self::assertFalse($this->validator()->submit($form, ['Default']));
        self::assertSame(['' => 'Add a second row.'], $form->validation->errors());
    }

    public function testAValidationDoesNotRunAgainForTheSameValues(): void
    {
        $form = new ValidatedForm();
        $validator = $this->validator();
        $validator->submit($form, []);
        $validator->settle($form);
        self::assertSame(1, $form->validations);

        // A handler changed a value: the errors are for the old values.
        $form->email = 'ada@example.com';
        $validator->settle($form);
        self::assertSame(2, $form->validations);
    }

    public function testAnErrorOfAHandlerIsAnErrorOfTheField(): void
    {
        $validation = new Validation()->withError('email', 'This email is taken.');

        self::assertNotSame($validation, $validation->withError('email', 'x'));
        self::assertSame('This email is taken.', $validation->error('email'));
        self::assertTrue($validation->hasError('email'));
        self::assertFalse($validation->hasError('password'));
        self::assertSame(['email' => 'This email is taken.'], $validation->errors());
        self::assertFalse($validation->valid());
        self::assertFalse($validation->fieldsValid());
        self::assertFalse($validation->touched('email'), 'it touches no field');

        // It goes before the error of a constraint, and a new one replaces it.
        $validation = new Validation(['email'], null, ['email' => 'Too short.'])->withError('email', 'Taken.')->withError('email', 'Banned.');
        self::assertSame('Banned.', $validation->error('email'));
        self::assertSame(['email' => 'Banned.'], $validation->errors());
    }

    public function testTheStateKeepsTheErrorsOfTheHandlers(): void
    {
        $validation = new Validation()->withError('draft.name', 'Taken.');
        self::assertSame(['added' => ['draft.name' => 'Taken.']], $validation->toState());
        self::assertSame('Taken.', Validation::fromState($validation->toState())->error('draft.name'));
        self::assertSame([], Validation::fromState(['added' => ['email' => 3]])->toState());
    }

    public function testAValidationKeepsTheErrorOfAHandler(): void
    {
        $form = new ValidatedForm();
        $validator = $this->validator();
        $validator->touch($form, ['email' => true]);
        $form->validation = $form->validation->withError('rows.0.name', 'Taken.');

        // A handler changed a value: the validation runs again.
        $form->email = 'ada@example.com';
        $validator->settle($form);
        self::assertSame(['rows.0.name' => 'Taken.'], $form->validation->errors());

        // A change of another field keeps it too.
        $validator->touch($form, ['email' => true]);
        $validator->settle($form);
        self::assertSame('Taken.', $form->validation->error('rows.0.name'));
    }

    public function testAChangeOfTheFieldRemovesTheErrorOfAHandler(): void
    {
        $form = new ValidatedForm();
        $form->email = 'ada@example.com';
        $validator = $this->validator();
        $form->validation = new Validation()->withError('email', 'Taken.')->withError('rows.0.name', 'Taken.')->withError('', 'Taken.');

        $validator->touch($form, ['email' => true]);
        self::assertNull($form->validation->error('email'));

        // A parent of the path is the field too.
        $validator->touch($form, ['rows' => true]);
        self::assertSame(['' => 'Taken.'], $form->validation->errors(), 'a change of a field keeps the error of the class');
    }

    public function testARejectedChangeOfTheFieldRemovesTheErrorOfAHandler(): void
    {
        $form = new ValidatedForm();
        $form->validation = new Validation()->withError('email', 'Taken.');
        $this->validator()->touch($form, ['email' => false]);

        self::assertNull($form->validation->error('email'));
    }

    public function testASubmitOfAHandlerWithNoValidatedRemovesTheErrorsOfTheHandlers(): void
    {
        $form = new ValidatedForm();
        $form->validation = new Validation()->withError('email', 'Taken.')->withError('', 'Taken.');
        $invoker = new HandlerInvoker([new ValidationMiddleware($this->validator())]);

        // Another event keeps them.
        $invoker->invoke($form->check(...), new Event('click'));
        self::assertSame(['' => 'Taken.', 'email' => 'Taken.'], $form->checked);

        // The handler runs with no old error, and finds them again.
        $invoker->invoke($form->check(...), new Event('submit'));
        self::assertSame([], $form->checked);
        self::assertTrue($form->validation->valid());
    }

    public function testASubmitRemovesTheErrorsOfTheHandlers(): void
    {
        $form = new ValidatedForm();
        $form->email = 'ada@example.com';
        $form->rows = [['name' => 'Ada'], ['name' => 'Bob']];
        $form->validation = new Validation()->withError('email', 'Taken.');

        self::assertTrue($this->validator()->submit($form, []), 'the handler runs and finds the error again');
        self::assertTrue($form->validation->valid());
    }

    public function testAComponentWithNoTouchedFieldIsNotValidated(): void
    {
        $form = new ValidatedForm();
        $this->validator()->settle($form);

        self::assertSame(0, $form->validations);
    }

    public function testAComponentWithNoValidationPropertyIsNotValidated(): void
    {
        $validator = $this->validator();
        $validator->touch(new UnvalidatedForm(), ['email' => true]);
        $validator->settle(new UnvalidatedForm());

        $this->addToAssertionCount(1);
    }

    public function testAComponentHasAtMostOneValidationProperty(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('more than one #[State] property of the type Phem\Validation ("billing", "shipping")');

        new ComponentMetadata()->validationProperty(TwoValidationsForm::class);
    }

    public function testLiveValidationNeedsTheValidator(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('install symfony/validator');

        $form = new ValidatedForm();
        $validator = new StateValidator();
        $validator->touch($form, ['email' => true]);
        $validator->settle($form);
    }

    public function testAValidatedHandlerStopsTheEventWithErrors(): void
    {
        $form = new ValidatedForm();
        $invoker = new HandlerInvoker([new ValidationMiddleware($this->validator())]);

        self::assertNull($invoker->invoke($form->save(...), new Event('submit')));
        self::assertFalse($form->saved);

        $form->email = 'ada@example.com';
        $form->rows = [['name' => 'Ada'], ['name' => 'Bob']];
        $invoker->invoke($form->save(...), new Event('submit'));
        self::assertTrue($form->saved);
    }

    public function testAValidatedClosureStopsTheEventWithErrors(): void
    {
        $form = new ValidatedForm();
        $invoker = new HandlerInvoker([new ValidationMiddleware($this->validator())]);

        self::assertNull($invoker->invoke($form->quickSave(), new Event('submit')));
        self::assertFalse($form->saved);
    }

    public function testAValidatedHandlerNeedsAValidationProperty(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[State] property of the type Phem\Validation');

        new HandlerInvoker([new ValidationMiddleware($this->validator())])->invoke(new UnvalidatedForm()->save(...), new Event('submit'));
    }

    private function validator(): StateValidator
    {
        return new StateValidator(SymfonyValidation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }
}

#[Assert\Callback([ValidatedForm::class, 'rows'])]
final class ValidatedForm
{
    #[State, Assert\NotBlank]
    public string $email = '';

    /** @var list<array{name: string}> */
    #[State, Assert\All([new Assert\Collection(fields: ['name' => new Assert\NotBlank()])])]
    public array $rows = [['name' => '']];

    #[State] public Validation $validation;
    public bool $saved = false;

    /** @var array<string, string> the errors that check() saw */
    public array $checked = [];

    public int $validations = 0;

    public static function rows(self $form, ExecutionContextInterface $context): void
    {
        ++$form->validations;
        if (1 === \count($form->rows)) {
            $context->addViolation('Add a second row.');
        }
    }

    #[Validated]
    public function save(): void
    {
        $this->saved = true;
    }

    public function check(): void
    {
        $this->checked = $this->validation->errors();
    }

    public function quickSave(): \Closure
    {
        return #[Validated] fn () => $this->saved = true;
    }
}

final class NullableValidationForm
{
    #[State] public ?Validation $validation = null;
}

final class TwoValidationsForm
{
    #[State] public Validation $billing;
    #[State] public Validation $shipping;
}

final class UnvalidatedForm
{
    #[State, Assert\NotBlank]
    public string $email = '';

    #[Validated]
    public function save(): void
    {
    }
}
