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

    public function testTheSourceOfADemoHasItsConstantsAndItsHelpers(): void
    {
        $source = Gallery::source('MenuFilterDemo');

        // The constants, the demo, then the helpers that it calls, also through another helper.
        self::assertStringStartsWith("<?phem\n\nuse Phem\\Attribute\\Component;\n", $source);
        self::assertLessThan(strpos($source, 'function MenuFilterDemo('), strpos($source, 'const MENU_FILTER_SHARE = '));
        self::assertGreaterThan(strpos($source, 'function MenuFilterDemo('), strpos($source, 'function MenuFilterSubmenu('));
        self::assertStringContainsString('function MenuClearIcon(', $source);

        // Not the page, the other demos, or the constants of the other demos.
        self::assertStringNotContainsString('MenuPage', $source);
        self::assertStringNotContainsString('function MenuHeroDemo(', $source);
        self::assertStringNotContainsString('MENU_ANIMATED', $source);
    }

    public function testTheSourceOfADemoClass(): void
    {
        $source = Gallery::source('ButtonLoadingDemo');

        self::assertStringContainsString("#[Component]\nfinal class ButtonLoadingDemo\n{", $source);
        self::assertStringContainsString('use Phem\\Attribute\\Component;', $source);
        self::assertStringNotContainsString('ButtonHeroDemo', $source);
    }

    public function testTheSourceOfADemoKeepsALongUseGroupOnOneLine(): void
    {
        $source = Gallery::source('MenuCheckboxDemo');

        self::assertMatchesRegularExpression('/^use function Phem\\\\Ui\\\\Base\\\\\{MenuCheckboxItem, [^\n]+\};$/m', $source);
    }

    public function testTheSourceOfAnUnknownDemo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Gallery::source('NoSuchDemo');
    }
}
