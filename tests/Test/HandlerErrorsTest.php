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

/**
 * The errors that a handler adds to a field with Validation::withError()
 * (tests/Fixtures/app/src/Ui/Validation/HandlerErrorsPage.phem).
 */
final class HandlerErrorsTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheErrorOfAHandlerShowsAsTheErrorOfTheField(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Shoes'])
            ->assertSelectorExists('input[name=name][aria-invalid=true]')
            ->assertSelectorTextContains('p.name-error', 'This name is taken.')
            ->assertSelectorNotExists('input[name=pet][aria-invalid]')
            ->assertState('saved', '', in: 'form')
            ->assertState('validation', ['groups' => ['Default'], 'added' => ['name' => 'This name is taken.']], in: 'form');
    }

    public function testTheErrorOfAHandlerStaysForAnotherEvent(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Shoes'])
            ->click('#count')
            ->assertSelectorTextContains('#count', 'Count 1')
            ->assertSelectorTextContains('p.name-error', 'This name is taken.')
            // A change of another field validates again, and keeps it.
            ->change('input[name=pet]', 'Rex')
            ->assertSelectorTextContains('p.name-error', 'This name is taken.');
    }

    public function testAChangeOfTheFieldRemovesTheErrorOfAHandler(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Shoes'])
            ->change('input[name=name]', 'Boots')
            ->assertSelectorNotExists('input[name=name][aria-invalid]')
            ->assertSelectorNotExists('p.name-error')
            // The constraints of the field show again.
            ->change('input[name=name]', '')
            ->assertSelectorTextContains('p.name-error', 'Enter a name.');
    }

    public function testTheNextSubmitFindsTheErrorAgain(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Shoes'])
            ->submit('form')
            ->assertSelectorTextContains('p.name-error', 'This name is taken.')
            ->fill('input[name=name]', 'Boots')
            ->submit('form')
            ->assertSelectorNotExists('p.name-error')
            ->assertSelectorTextContains('#saved', 'Boots');
    }

    public function testTheFieldOfADraftObjectShowsTheErrorOfAHandler(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Boots', 'pet' => 'Max'])
            ->assertSelectorExists('input[name=pet][aria-invalid=true]')
            ->assertSelectorTextContains('p.pet-error', 'This pet is taken.')
            ->assertSelectorNotExists('p.name-error')
            ->change('input[name=pet]', 'Rex')
            ->assertSelectorNotExists('p.pet-error');
    }

    public function testTheFieldOfAChildShowsTheErrorOfAHandlerOfTheParent(): void
    {
        $this->visit('/validation/handler-errors')
            ->submit('form', ['name' => 'Boots', 'slug' => 'shoes'])
            ->assertSelectorExists('input[name=slug][aria-invalid=true]')
            ->assertSelectorTextContains('em', 'This slug is taken.')
            // The child binds the property of the parent: a change removes the error.
            ->change('input[name=slug]', 'boots')
            ->assertSelectorNotExists('em')
            ->assertSelectorNotExists('[aria-invalid]');
    }
}
