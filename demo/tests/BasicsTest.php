<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

/** The components with no controller: the markup and the ARIA of the server render. */
final class BasicsTest extends DemoTestCase
{
    public function testButton(): void
    {
        $page = $this->visitComponent('button');

        $button = $page->getByRole('button', ['name' => 'Submit']);
        expect($button)->toHaveAttribute('type', 'button');
        $button->focus();
        expect($button)->toBeFocused();
    }

    public function testInput(): void
    {
        $page = $this->visitComponent('input');

        $input = $page->getByRole('textbox', ['name' => 'Name']);
        $input->fill('Colm');
        expect($input)->toHaveValue('Colm');
    }

    public function testSeparator(): void
    {
        $page = $this->visitComponent('separator');

        expect($page->getByRole('separator'))->toHaveAttribute('aria-orientation', 'vertical');
    }

    public function testFieldset(): void
    {
        $page = $this->visitComponent('fieldset');

        expect($page->getByRole('group', ['name' => 'Billing details']))->toBeVisible();
        expect($page->getByRole('textbox', ['name' => 'Company']))->toBeEnabled();
    }

    public function testMeter(): void
    {
        $page = $this->visitComponent('meter');

        $meter = $page->getByRole('meter', ['name' => 'Storage Used']);
        expect($meter)->toHaveAttribute('aria-valuenow', '24');
        expect($meter)->toContainText('24%');
    }

    public function testProgress(): void
    {
        $page = $this->visitComponent('progress');

        $progress = $page->getByRole('progressbar', ['name' => 'Export data']);
        expect($progress)->toHaveAttribute('aria-valuenow', '20');
        expect($progress)->toHaveAttribute('data-progressing', '');
    }
}
