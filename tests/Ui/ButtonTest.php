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
            ->assertSelectorExists('button[aria-disabled="true"][data-disabled]:not([disabled])');
    }

    public function testTheCallerClassesMerge(): void
    {
        $html = $this->mount('Phem\Ui\Base\Button', ['className' => 'w-full', 'children' => new Html('Submit')])->html();

        self::assertMatchesRegularExpression('/class="flex h-8 [^"]* w-full"/', $html);
    }

    public function testAButtonCanRenderAsALink(): void
    {
        $this->mount('Phem\Ui\Base\Button', ['tag' => 'a', 'href' => '/docs', 'children' => new Html('Docs')])
            ->assertSelectorExists('a[href="/docs"][role="button"][tabindex="0"]');
    }
}
