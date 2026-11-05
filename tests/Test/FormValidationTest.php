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

/** The live validation of a Symfony form (tests/Fixtures/app/src/Ui/Form/FormPage.phpm). */
final class FormValidationTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAChangeShowsTheErrorOfTheChangedFieldOnly(): void
    {
        $this->visit('/form')
            ->change('input[name="account[email]"]', 'ada')
            ->assertSelectorTextContains('#email-row', 'This value is not a valid email address.')
            ->assertSelectorNotExists('#password-row li')
            ->assertSelectorNotExists('#city-row li')
            ->assertSelectorNotExists('#tags-row li')
            ->assertSelectorExists('input[name="account[email]"][data-phem-touched]')
            ->assertSelectorNotExists('input[name="account[city]"][data-phem-touched]')
            ->assertSelectorExists('input[name="account[email]"][value="ada"]');
    }

    public function testTheTouchedFieldsKeepTheirErrors(): void
    {
        $this->visit('/form')
            ->change('input[name="account[email]"]', 'ada')
            ->change('input[name="account[city]"]', '')
            ->assertSelectorTextContains('#city-row', 'Enter a city.')
            ->assertSelectorTextContains('#email-row', 'This value is not a valid email address.')
            ->assertSelectorNotExists('#password-row li')
            ->change('input[name="account[email]"]', 'ada@example.com')
            ->assertSelectorNotExists('#email-row li')
            ->assertSelectorTextContains('#city-row', 'Enter a city.');
    }

    public function testAChangeOfACheckboxTouchesItsGroup(): void
    {
        $this->visit('/form')
            ->change('input[name="account[tags][]"][value="a"]', true)
            ->change('input[name="account[tags][]"][value="a"]', false)
            ->assertSelectorTextContains('#tags-row', 'Choose a tag.')
            ->assertSelectorExists('input[name="account[tags][]"][value="b"][data-phem-touched]')
            ->assertSelectorNotExists('#email-row li');
    }

    public function testAChangeHidesTheErrorOfTheRootForm(): void
    {
        $this->visit('/form')
            ->change('input[name="account[city]"]', 'Nowhere')
            ->assertSelectorNotExists('#form-errors li')
            ->assertSelectorNotExists('#city-row li');
    }

    public function testASubmitShowsAllTheErrorsAndTouchesAllTheFields(): void
    {
        $this->visit('/form')
            ->fill('input[name="account[city]"]', 'Nowhere')
            ->submit('#account')
            ->assertSelectorTextContains('#form-errors', 'We do not ship to Nowhere.')
            ->assertSelectorTextContains('#email-row', 'This value should not be blank.')
            ->assertSelectorTextContains('#password-row', 'This value should not be blank.')
            ->assertSelectorTextContains('#tags-row', 'Choose a tag.')
            ->assertSelectorExists('input[name="account[password]"][data-phem-touched]')
            ->assertState('saved', '', in: 'form')
            // The fields stay touched: a change does not hide the errors of the other fields.
            ->change('input[name="account[email]"]', 'ada@example.com')
            ->assertSelectorNotExists('#email-row li')
            ->assertSelectorTextContains('#password-row', 'This value should not be blank.')
            ->assertSelectorTextContains('#tags-row', 'Choose a tag.')
            // The user touched all the fields: the error of the form stays too.
            ->assertSelectorTextContains('#form-errors', 'We do not ship to Nowhere.');
    }

    public function testATouchedPasswordKeepsItsValue(): void
    {
        $this->visit('/form')
            ->change('input[name="account[password]"]', 'secret123')
            ->change('input[name="account[email]"]', 'ada')
            ->assertSelectorExists('input[name="account[password]"][value="secret123"]')
            ->assertSelectorNotExists('#password-row li');
    }

    public function testAValidSubmitGivesANewForm(): void
    {
        $this->visit('/form')
            ->change('input[name="account[email]"]', 'ada@example.com')
            ->change('input[name="account[password]"]', 'secret123')
            ->change('input[name="account[city]"]', 'Paris')
            ->change('input[name="account[tags][]"][value="b"]', true)
            ->submit('#account')
            ->assertState('saved', 'ada@example.com', in: 'form')
            ->assertSelectorNotExists('li')
            ->assertSelectorNotExists('[data-phem-touched]')
            ->assertSelectorNotExists('input[name="account[email]"][value]')
            ->assertSelectorNotExists('input[name="account[password]"][value]');
    }

    public function testAClickInTheFormSendsItsFields(): void
    {
        $this->visit('/form')
            ->change('input[name="account[email]"]', 'ada')
            ->fill('input[name="account[city]"]', 'Paris')
            ->click('#add-phone')
            ->assertSelectorExists('input[name="account[phones][0]"]')
            ->assertSelectorExists('input[name="account[email]"][value="ada"]')
            ->assertSelectorExists('input[name="account[city]"][value="Paris"]')
            ->assertSelectorTextContains('#email-row', 'This value is not a valid email address.')
            ->assertSelectorNotExists('#password-row li');
    }

    public function testAButtonOutsideTheFormSendsItsFields(): void
    {
        $this->visit('/form')
            ->fill('input[name="account[email]"]', 'ada@example.com')
            ->click('#outside')
            ->assertSelectorExists('input[name="account[phones][0]"]')
            ->assertSelectorExists('input[name="account[email]"][value="ada@example.com"]')
            ->assertSelectorExists('input[name="account[email]"][data-phem-touched]');
    }

    public function testASaveWithAClickThatTouchesAllTheFieldsShowsAllTheErrors(): void
    {
        $this->visit('/form')
            ->change('input[name="account[email]"]', 'ada@example.com')
            ->click('#save-click')
            ->assertState('saved', '', in: 'form')
            ->assertSelectorTextContains('#password-row', 'This value should not be blank.')
            ->assertSelectorTextContains('#city-row', 'Enter a city.')
            ->assertSelectorExists('input[name="account[city]"][data-phem-touched]');
    }
}
