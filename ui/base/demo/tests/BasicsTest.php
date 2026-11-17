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

        $button = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Submit']);
        expect($button)->toHaveAttribute('type', 'button');
        $button->focus();
        expect($button)->toBeFocused();
    }

    public function testButtonRenderedAsAnotherTag(): void
    {
        $page = $this->visitComponent('button');

        $button = $page->getByRole('button', ['name' => 'Button that can contain complex children']);
        self::assertSame('DIV', $button->evaluate('el => el.tagName'));
        expect($button)->toHaveAttribute('tabindex', '0');
        $button->focus();
        expect($button)->toBeFocused();
    }

    public function testButtonKeepsTheFocusInItsLoadingState(): void
    {
        $page = $this->visitComponent('button');
        $example = $page->getByRole('region', ['name' => 'Loading states'])->locator('[data-demo="preview"]');

        $button = $example->getByRole('button');
        expect($button)->toHaveAttribute('aria-labelledby', 'loading-label');
        expect($example->getByRole('button', ['name' => 'Submit', 'exact' => true]))->toBeVisible();
        $button->focus();

        // `phem:lock` disables the button at the click, before the server answers: read it in the
        // same task as the click. A second click sends no event.
        $state = $button->evaluate(<<<'JS'
            (el) => {
                let events = 0
                document.addEventListener('phem:queue', () => events++)
                el.click()
                el.click()

                return [el.getAttribute('aria-disabled'), document.getElementById('loading-label').innerText.trim(), events]
            }
            JS);
        self::assertSame(['true', 'Submitting', 1], $state);
        expect($button)->toBeFocused();

        // The answer comes after 4 seconds: the button is enabled again.
        expect($example->getByRole('button', ['name' => 'Submit', 'exact' => true]))->withTimeout(10_000)->toBeVisible();
        expect($button)->not()->toHaveAttribute('aria-disabled', 'true');
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
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

        $progress = $hero->getByRole('progressbar', ['name' => 'Export data']);
        expect($progress)->toHaveAttribute('data-progressing', '');
        self::assertGreaterThanOrEqual(20, (int) $progress->getAttribute('aria-valuenow'));

        // The value grows each second, as in the Base UI demo.
        $page->waitForFunction('() => Number(document.querySelector(\'[role="progressbar"]\').getAttribute("aria-valuenow")) > 45', null, ['timeout' => 30_000]);
        expect($progress)->toContainText('%');
    }
}
