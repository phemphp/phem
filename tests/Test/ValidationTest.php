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

namespace Phem\Tests\Test;

use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The live validation of the #[State] properties (tests/Fixtures/app/src/Ui/Validation/ValidationPage.phem). */
final class ValidationTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAChangeShowsTheErrorOfTheChangedFieldOnly(): void
    {
        $this->visit('/validation')
            ->change('input[name=email]', 'ada')
            ->assertSelectorTextContains('#email-error', 'This value is not a valid email address.')
            ->assertSelectorNotExists('#password-error')
            ->assertSelectorNotExists('#city-error')
            ->assertState('validation', ['touched' => ['email'], 'errors' => ['email' => 'This value is not a valid email address.']], in: 'form');
    }

    public function testAValidValueRemovesTheError(): void
    {
        $this->visit('/validation')
            ->change('input[name=email]', 'ada')
            ->change('input[name=email]', 'ada@example.com')
            ->assertSelectorNotExists('#email-error')
            ->assertState('validation', ['touched' => ['email']], in: 'form');
    }

    public function testTheErrorOfAPathHasThePathOfTheBoundField(): void
    {
        $this->visit('/validation')
            ->change('input[name=city]', 'Rome')
            ->assertSelectorNotExists('#city-error')
            ->change('input[name=city]', '')
            ->assertSelectorTextContains('#city-error', 'Enter a city.')
            ->assertState('validation', ['touched' => ['address.city'], 'errors' => ['address.city' => 'Enter a city.']], in: 'form');
    }

    public function testAConstraintOnTwoFieldsUpdatesTheOtherField(): void
    {
        $this->visit('/validation')
            ->change('input[name=password]', 'secret123')
            ->change('input[name=confirm]', 'secret12')
            ->assertSelectorTextContains('#confirm-error', 'The passwords do not match.')
            // The change of the password validates the confirmation again.
            ->change('input[name=password]', 'secret12')
            ->assertSelectorNotExists('#confirm-error');
    }

    public function testAValidatedHandlerShowsAllTheErrorsAndDoesNotRun(): void
    {
        $this->visit('/validation')
            ->submit('form')
            ->assertSelectorTextContains('#email-error', 'This value should not be blank.')
            ->assertSelectorTextContains('#password-error', 'This value is too short.')
            ->assertSelectorTextContains('#city-error', 'Enter a city.')
            // Not in the default group.
            ->assertSelectorNotExists('#nickname-error')
            ->assertState('saved', '', in: 'form');
    }

    public function testAValidatedHandlerRunsWithNoError(): void
    {
        $this->visit('/validation')
            ->fill('input[name=email]', 'ada@example.com')
            ->fill('input[name=password]', 'secret123')
            ->fill('input[name=confirm]', 'secret123')
            ->fill('input[name=city]', 'Rome')
            ->submit('form')
            ->assertSelectorTextContains('#saved', 'ada@example.com')
            // The handler resets the validation.
            ->assertState('validation', [], in: 'form');
    }

    public function testTheGroupsOfAHandlerStayForTheNextChanges(): void
    {
        $this->visit('/validation')
            ->click('#draft')
            ->assertSelectorTextContains('#nickname-error', 'Enter a nickname.')
            ->assertSelectorNotExists('#email-error')
            ->assertState('validation', ['groups' => ['draft'], 'errors' => ['nickname' => 'Enter a nickname.']], in: 'form')
            // A change validates the draft group: the empty email has no error.
            ->change('input[name=nickname]', 'ada')
            ->assertSelectorNotExists('#nickname-error')
            ->assertSelectorNotExists('#email-error')
            ->click('#draft')
            ->assertSelectorTextContains('#saved', 'draft of ada');
    }

    public function testAChildThatGetsTheValidationRendersAgain(): void
    {
        $this->visit('/validation')
            ->change('input[name=email]', 'ada')
            ->assertSelectorTextContains('.child-error', 'This value is not a valid email address.')
            ->change('input[name=email]', 'ada@example.com')
            ->assertDontSee('This value is not a valid email address.');
    }

    public function testAHandlerThatChangesAFieldValidatesAgain(): void
    {
        $this->visit('/validation')
            ->change('input[name=email]', 'ada')
            ->assertSelectorExists('#email-error')
            ->click('#fix-email')
            ->assertSelectorNotExists('#email-error')
            ->assertState('validation', ['touched' => ['email']], in: 'form');
    }

    public function testAValueThatDoesNotFitTheTypeGetsAnError(): void
    {
        $this->visit('/validation')
            ->change('input[name=age]', 'abc')
            ->assertSelectorTextContains('#age-error', 'This value is not valid.')
            ->assertState('age', 1, in: 'form')
            ->change('input[name=age]', '-3')
            ->assertSelectorTextContains('#age-error', 'This value should be positive.')
            ->change('input[name=age]', '5')
            ->assertSelectorNotExists('#age-error');
    }

    public function testAValidatedClosureDoesNotRunWithErrors(): void
    {
        $this->visit('/validation')
            ->click('#quick')
            ->assertSelectorExists('#email-error')
            ->assertState('saved', '', in: 'form');
    }

    public function testAnOnBlurHandlerTouchesAField(): void
    {
        $this->visit('/validation')
            ->blur('input[name=city]')
            ->assertSelectorTextContains('#city-error', 'Enter a city.')
            ->assertSelectorNotExists('#email-error');
    }

    public function testAnotherHandlerRunsWithNoValidation(): void
    {
        $this->visit('/validation')
            ->click('#rename')
            ->assertSelectorTextContains('#saved', 'renamed')
            ->assertSelectorNotExists('#email-error')
            ->assertState('validation', [], in: 'form');
    }
}
