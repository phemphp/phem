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

use Phem\Form\TouchedFields;
use Phem\Form\TouchedFieldsExtension;
use Phem\Form\TouchedFieldsRequestHandler;
use Phem\Form\TouchedForms;
use Phem\Tests\Fixtures\AccountType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationRequestHandler;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

final class TouchedFieldsTest extends TestCase
{
    public function testAFieldTouchesItsParentsAndItsChildren(): void
    {
        $touched = TouchedFields::of(['account[birthday][year]', 'account[tags][]']);

        self::assertTrue($touched->touches('account[birthday][year]'));
        self::assertTrue($touched->touches('account[birthday]'));
        self::assertTrue($touched->touches('account[tags]'));
        self::assertTrue($touched->touches('account[tags][0]'));
        self::assertTrue($touched->touches('account[tags][]'));
        self::assertFalse($touched->touches('account[birthday][month]'));
        self::assertFalse($touched->touches('account[tag]'));
        self::assertFalse($touched->touches('account[email]'));
    }

    public function testAllTouchesEachField(): void
    {
        self::assertTrue(TouchedFields::all()->isAll());
        self::assertTrue(TouchedFields::all()->touches('account[email]'));
        self::assertFalse(TouchedFields::of([])->isAll());
    }

    public function testTheValuesThatAreNotNamesAreLeftOut(): void
    {
        $touched = TouchedFields::of(['account[email]', 3, ['x'], '', '[]']);

        self::assertTrue($touched->touches('account[email]'));
        self::assertFalse($touched->touches('3'));
        self::assertFalse(TouchedFields::of('account[email]')->touches('account[email]'));
    }

    public function testANumericNameStaysAString(): void
    {
        // The children of a form with no name: a list of a CollectionType.
        $touched = TouchedFields::of(['0', '1[]']);

        self::assertTrue($touched->touches('0'));
        self::assertTrue($touched->touches('1'));
        self::assertFalse($touched->touches('2'));
    }

    public function testTouchAllTouchesAllTheFieldsOfTheRequest(): void
    {
        $request = Request::create('/_phem/event', 'POST');

        self::assertSame($request, TouchedFields::touchAll($request));
        self::assertTrue($request->attributes->get(TouchedFields::REQUEST_ATTRIBUTE)?->isAll());
    }

    public function testAnErrorThatBubblesShowsWhenTheUserTouchedItsField(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = fn () => $this->factory($forms)->createNamedBuilder('f')
            ->add('code', TextType::class, ['error_bubbling' => true, 'constraints' => [new NotBlank()]])
            ->add('name', TextType::class, ['constraints' => [new NotBlank()]])
            ->getForm();

        $handler->handleRequest($code = $form(), $this->request(['code' => '', 'name' => ''], TouchedFields::of(['f[code]']), 'f'));
        $handler->handleRequest($name = $form(), $this->request(['code' => '', 'name' => ''], TouchedFields::of(['f[name]']), 'f'));
        $codeView = $code->createView();
        $nameView = $name->createView();

        self::assertCount(1, $codeView->vars['errors']);
        self::assertFalse($codeView->vars['valid']);
        self::assertCount(0, $codeView['name']->vars['errors']);
        self::assertCount(0, $nameView->vars['errors']);
        self::assertCount(1, $nameView['name']->vars['errors']);
        self::assertFalse($nameView->vars['valid'], 'a child with an error');
    }

    public function testTheErrorsOfTheFormShowWhenTheUserTouchedAllTheFields(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = $this->factory($forms)->create(AccountType::class);
        $fields = ['email' => 'ada@example.com', 'password' => 'secret123', 'city' => 'Nowhere', 'tags' => ['a']];

        $handler->handleRequest($form, $this->request($fields, TouchedFields::of(['account[email]', 'account[password]', 'account[city]', 'account[tags][]'])));
        $view = $form->createView();

        self::assertCount(1, $view->vars['errors']);
        self::assertFalse($view->vars['valid']);
    }

    public function testAnEventGivesTheTouchedFieldsOfItsForm(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = $this->factory($forms)->create(AccountType::class);

        $handler->handleRequest($form, $this->request(['email' => 'ada', 'city' => 'Nowhere'], TouchedFields::of(['account[email]'])));
        $view = $form->createView();

        self::assertCount(1, $view['email']->vars['errors']);
        self::assertFalse($view['email']->vars['valid']);
        self::assertSame('', $view['email']->vars['attr']['data-phem-touched'] ?? null);
        self::assertCount(0, $view['password']->vars['errors']);
        self::assertTrue($view['password']->vars['valid']);
        self::assertCount(0, $view['city']->vars['errors']);
        self::assertArrayNotHasKey('data-phem-touched', $view['city']->vars['attr']);
        // The error of the constraint on the class.
        self::assertCount(0, $view->vars['errors']);
    }

    public function testTheFormKeepsTheErrorsThatTheViewHides(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = $this->factory($forms)->create(AccountType::class);

        // A crafted event: the submit handler with no touched field.
        $handler->handleRequest($form, $this->request(['email' => 'ada@example.com', 'city' => 'Nowhere'], TouchedFields::of([])));

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('password')->getErrors());
        self::assertCount(1, $form->getErrors());
        self::assertCount(0, $form->createView()['password']->vars['errors']);
    }

    public function testASubmitShowsAllTheErrors(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = $this->factory($forms)->create(AccountType::class);

        $handler->handleRequest($form, $this->request(['city' => 'Nowhere'], TouchedFields::all()));
        $view = $form->createView();

        self::assertCount(1, $view['password']->vars['errors']);
        self::assertCount(1, $view->vars['errors']);
        self::assertSame('', $view['password']->vars['attr']['data-phem-touched'] ?? null);
    }

    public function testARequestThatIsNotAnEventKeepsAllTheErrors(): void
    {
        $handler = $this->handler($forms = new TouchedForms());
        $form = $this->factory($forms)->create(AccountType::class);

        $handler->handleRequest($form, $this->request(['email' => 'ada']));

        self::assertCount(1, $form->get('email')->getErrors());
        self::assertCount(1, $form->get('password')->getErrors());
        self::assertCount(1, $form->get('city')->getErrors());
        self::assertArrayNotHasKey('data-phem-touched', $form->createView()['email']->vars['attr']);
    }

    private function handler(TouchedForms $forms): TouchedFieldsRequestHandler
    {
        return new TouchedFieldsRequestHandler(new HttpFoundationRequestHandler(), $forms);
    }

    private function factory(TouchedForms $forms): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()))
            ->addTypeExtension(new TouchedFieldsExtension($forms))
            ->getFormFactory();
    }

    /** @param array<string, string|list<string>> $fields */
    private function request(array $fields, ?TouchedFields $touched = null, string $form = 'account'): Request
    {
        $request = Request::create('/_phem/event', 'POST', [$form => $fields]);
        if (null !== $touched) {
            $request->attributes->set(TouchedFields::REQUEST_ATTRIBUTE, $touched);
        }

        return $request;
    }
}
