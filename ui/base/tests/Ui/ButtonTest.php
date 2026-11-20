<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Html;
use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ButtonTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testTheButtonIsANativeButton(): void
    {
        $this->mount('Phem\Ui\Base\Button', ['children' => new Html('Submit')])
            ->assertSee('Submit')
            ->assertSelectorExists('button[type="button"]:not([disabled])');
    }

    public function testADisabledButtonHasTheDisabledAttributes(): void
    {
        $this->mount('Phem\Ui\Base\Button', ['disabled' => true, 'children' => new Html('Submit')])
            ->assertSelectorExists('button[disabled][data-disabled]');
    }

    public function testAFocusableDisabledButtonKeepsTheFocus(): void
    {
        $this->mount('Phem\Ui\Base\Button', ['disabled' => true, 'focusableWhenDisabled' => true, 'children' => new Html('Submit')])
            // The `phem-button` controller stops the click of a button with `aria-disabled`.
            ->assertSelectorExists('button[data-controller="phem-button"][aria-disabled="true"][data-disabled]:not([disabled])');
    }

    public function testALockedButtonHasTheDisabledLook(): void
    {
        $html = $this->mount('Phem\Ui\Base\Button', ['phem:lock' => true, 'children' => new Html('Submit')])
            // `phem:lock` gives the button `aria-disabled="true"` while its event runs.
            ->assertSelectorExists('button[data-phem-lock]')
            ->html();

        self::assertStringContainsString('aria-disabled:text-muted-foreground', $html);
        self::assertStringContainsString('hover:not-data-disabled:not-aria-disabled:bg-accent', $html);
    }

    public function testTheButtonTakesItsColorsFromTheTheme(): void
    {
        $html = $this->mount('Phem\Ui\Base\Button', ['children' => new Html('Submit')])
            ->assertSelectorExists('button[class~="bg-background"][class~="text-foreground"][class~="border-input"][class~="rounded-control"][class~="focus-visible:outline-ring"]')
            ->html();

        // The theme gives the dark values: the button has no dark: class.
        self::assertStringNotContainsString('dark:', $html);
    }

    public function testTheCallerClassesMerge(): void
    {
        $html = $this->mount('Phem\Ui\Base\Button', ['className' => 'w-full', 'children' => new Html('Submit')])->html();

        self::assertMatchesRegularExpression('/class="flex h-8 [^"]* w-full"/', $html);
    }

    public function testAButtonCanRenderAsALink(): void
    {
        $this->mount('Phem\Ui\Base\Button', ['tag' => 'a', 'href' => '/docs', 'children' => new Html('Docs')])
            // The `phem-button` controller gives Enter and Space to a button that is not a `<button>`.
            ->assertSelectorExists('a[href="/docs"][data-controller="phem-button"][role="button"][tabindex="0"]');

        $this->mount('Phem\Ui\Base\Button', ['children' => new Html('Submit')])
            ->assertSelectorExists('button:not([data-controller])');
    }
}
