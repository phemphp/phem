<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ui\Gallery;

use function Playwright\Testing\expect;

final class GalleryTest extends DemoTestCase
{
    public function testTheHomePageLinksToEachComponent(): void
    {
        $page = $this->visit('/');

        foreach (Gallery::components() as $slug => $name) {
            expect($page->getByRole('link', ['name' => $name, 'exact' => true]))->toHaveAttribute('href', '/components/'.$slug);
        }
    }
}
