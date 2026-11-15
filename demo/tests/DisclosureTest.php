<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class DisclosureTest extends DemoTestCase
{
    public function testAccordionOpensOneItemAtATime(): void
    {
        $page = $this->visitComponent('accordion');

        $what = $page->getByRole('button', ['name' => 'What is Base UI?']);
        $start = $page->getByRole('button', ['name' => 'How do I get started?']);
        $answer = $page->getByText('Base UI is a library of high-quality');

        expect($answer)->toBeHidden();
        $what->click();
        expect($what)->toHaveAttribute('aria-expanded', 'true');
        expect($what)->toHaveAttribute('data-panel-open', '');
        expect($answer)->toBeVisible();

        // The arrow keys move between the triggers.
        $page->keyboard()->press('ArrowDown');
        expect($start)->toBeFocused();
        $page->keyboard()->press('Enter');
        expect($start)->toHaveAttribute('aria-expanded', 'true');
        expect($what)->toHaveAttribute('aria-expanded', 'false');
        expect($answer)->toBeHidden();
    }

    public function testCollapsible(): void
    {
        $page = $this->visitComponent('collapsible');

        $trigger = $page->getByRole('button', ['name' => 'Recovery keys']);
        expect($page->getByText('alien-bean-pasta'))->toBeHidden();

        $trigger->click();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        expect($page->getByText('alien-bean-pasta'))->toBeVisible();

        $trigger->click();
        expect($trigger)->toHaveAttribute('aria-expanded', 'false');
        expect($page->getByText('alien-bean-pasta'))->toBeHidden();
    }

    public function testTabs(): void
    {
        $page = $this->visitComponent('tabs');

        $overview = $page->getByRole('tab', ['name' => 'Overview']);
        $projects = $page->getByRole('tab', ['name' => 'Projects']);

        expect($overview)->toHaveAttribute('aria-selected', 'true');
        expect($page->getByRole('tabpanel'))->toHaveText('Workspace stats and activity.');

        $projects->click();
        expect($projects)->toHaveAttribute('aria-selected', 'true');
        expect($projects)->toHaveAttribute('data-active', '');
        expect($overview)->toHaveAttribute('aria-selected', 'false');
        expect($page->getByRole('tabpanel'))->toHaveText('Milestones and deadlines.');

        // The arrow keys move the focus, and Enter selects.
        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('tab', ['name' => 'Account']))->toBeFocused();
        $page->keyboard()->press('Enter');
        expect($page->getByRole('tabpanel'))->toHaveText('Profile and preferences.');
    }
}
