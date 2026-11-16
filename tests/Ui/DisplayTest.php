<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DisplayTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testTheProgressShowsItsValue(): void
    {
        $this->mount('Phem\Ui\Base\Tests\App\ProgressDemo')
            ->assertSelectorExists('#export[role="progressbar"][aria-valuenow="20"][aria-labelledby="export-label"][data-progressing]')
            ->assertSelectorExists('#export-label[data-progressing]')
            ->assertSelectorTextContains('#export span[aria-hidden]', '20%')
            ->assertSelectorExists('#export div[style*="width: 20%"]');
    }

    public function testANullProgressIsIndeterminate(): void
    {
        $this->mount('Phem\Ui\Base\Tests\App\ProgressDemo', ['value' => null])
            ->assertSelectorExists('#export[data-indeterminate]:not([aria-valuenow])');
    }

    public function testAFullProgressIsComplete(): void
    {
        $this->mount('Phem\Ui\Base\Tests\App\ProgressDemo', ['value' => 100.0])
            ->assertSelectorExists('#export[data-complete]');
    }

    public function testTheMeterShowsItsValue(): void
    {
        $this->mount('Phem\Ui\Base\Tests\App\MeterDemo')
            ->assertSelectorExists('#storage[role="meter"][aria-valuenow="24"][aria-valuetext="24%"]')
            ->assertSelectorExists('#storage div[style*="width: 24%"]');
    }

    public function testTheFieldsetHasALegendAndASeparator(): void
    {
        $this->mount('Phem\Ui\Base\Tests\App\FieldsetDemo')
            ->assertSelectorTextContains('fieldset legend', 'Billing details')
            // A legend is not a flex item: the fieldset gives it the margin of its gap.
            ->assertSelectorExists('fieldset[class~="[--fieldset-gap:1rem]"][class~="gap-[var(--fieldset-gap)]"][class~="[&>legend]:mb-[var(--fieldset-gap)]"]')
            ->assertSelectorExists('fieldset input[name="company"]')
            ->assertSelectorExists('[role="separator"][aria-orientation="vertical"][data-orientation="vertical"]');
    }
}
