<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ui\Gallery;

use function Playwright\Testing\expect;

final class GalleryTest extends DemoTestCase
{
    public function testTheSidebarHasTheComponentsOfBaseUiInTheirOrder(): void
    {
        $page = $this->visit('/');

        $links = $page->getByRole('navigation', ['name' => 'Components'])->getByRole('listitem')->getByRole('link');
        expect($links)->toHaveCount(\count(Gallery::components()));

        $index = 0;
        foreach (Gallery::components() as $slug => $component) {
            $link = $links->nth($index++);
            expect($link)->toHaveText($component['name']);
            expect($link)->toHaveAttribute('href', '/components/'.$slug);
        }
    }

    public function testEachPageHasTheTitleAndTheDescriptionOfBaseUi(): void
    {
        foreach (Gallery::components() as $slug => $component) {
            $page = $this->visit('/components/'.$slug);

            expect($page->getByRole('heading', ['level' => 1]))->toHaveText($component['name']);
            expect($page->getByText($component['description'], ['exact' => true]))->toBeVisible();
            expect($page->getByRole('link', ['name' => $component['name'], 'exact' => true]))->toHaveAttribute('aria-current', 'page');
        }
    }
}
