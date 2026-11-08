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

/** The #[MapForm] handlers (tests/Fixtures/app/src/Ui/MapForm/MapFormPage.phem). */
final class MapFormTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAValidFormGivesATypedObjectToTheHandler(): void
    {
        $this->visit('/map-form')
            ->submit('#closed', ['from' => '2026-12-24', 'size' => 'l', 'guests' => '3', 'notify' => true])
            ->assertSelectorTextContains('#saved', '2026-12-24 to 2026-12-24, Large, 3 guests, notify')
            ->assertSelectorNotExists('#from-error')
            ->assertState('validation', [], in: '#closed');
    }

    public function testAnInvalidFormShowsTheErrorsAndTheHandlerDoesNotRun(): void
    {
        $this->visit('/map-form')
            ->submit('#closed', ['from' => '', 'to' => 'tomorrow?', 'guests' => '0'])
            ->assertSelectorTextContains('#from-error', 'Choose the first day.')
            ->assertSelectorTextContains('#to-error', 'This value is not valid.')
            ->assertSelectorTextContains('#guests-error', 'This value should be positive.')
            ->assertSelectorTextContains('#saved', '')
            ->assertState('saved', '', in: '#closed')
            ->assertNotDispatched('toast');
    }

    public function testTheNextValidSubmitRemovesTheErrors(): void
    {
        $this->visit('/map-form')
            ->submit('#closed', ['from' => ''])
            ->assertSelectorExists('#from-error')
            ->submit('#closed', ['from' => '2026-12-24', 'to' => '2026-12-26', 'guests' => '1'])
            ->assertSelectorTextContains('#saved', '2026-12-24 to 2026-12-26, no size, 1 guests')
            ->assertSelectorNotExists('#from-error');
    }

    public function testWithoutAValidationPropertyTheFirstErrorIsAToast(): void
    {
        $this->visit('/map-form')
            ->submit('#quick')
            ->assertDispatched('toast', ['message' => 'Choose the first day.', 'type' => 'error'])
            ->assertSelectorTextContains('#quick-saved', '')
            ->submit('#quick', ['dates[from]' => '2026-12-31'])
            ->assertNotDispatched('toast')
            ->assertSelectorTextContains('#quick-saved', '2026-12-31');
    }

    public function testAValidSubmitRemovesTheErrorsWhenTheHandlerChangesNoState(): void
    {
        $this->visit('/map-form')
            ->submit('#holidays')
            ->assertSelectorTextContains('#holiday-error', 'Choose the first day.')
            ->assertNotDispatched('holiday')
            ->submit('#holidays', ['holiday[from]' => '2026-12-25'])
            ->assertDispatched('holiday', '2026-12-25')
            ->assertSelectorNotExists('#holiday-error');
    }

    public function testASecondInvalidSubmitShowsOnlyItsErrors(): void
    {
        $this->visit('/map-form')
            ->submit('#closed', ['from' => '', 'guests' => '1'])
            ->assertSelectorExists('#from-error')
            ->submit('#closed', ['from' => '2026-12-24', 'guests' => '0'])
            ->assertSelectorNotExists('#from-error')
            ->assertSelectorTextContains('#guests-error', 'This value should be positive.');
    }
}
