<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FormsTest extends KernelTestCase
{
    use InteractsWithPhem;

    private const string APP = 'Phem\Ui\Base\Tests\App\\';

    public function testTheFieldMarksItsParts(): void
    {
        $this->mount(self::APP.'FieldDemo')
            ->assertSelectorExists('form[data-controller="phem-form"] [data-controller="phem-field"][data-phem-field-validation-mode-value="onBlur"]')
            ->assertSelectorExists('[data-controller="phem-field"]#name')
            ->assertSelectorExists('label[for="name-control"][data-phem-field-target="label"]')
            ->assertSelectorExists('input#name-control[data-phem-field-target="control"][name="name"][required][value="Ada"]')
            ->assertSelectorExists('div#name-error-valueMissing[data-phem-field-target="error"][data-match="valueMissing"][hidden]')
            ->assertSelectorExists('p#name-description[data-phem-field-target="description"]')
            ->assertSelectorNotExists('#other');
    }

    public function testAFieldWithNoIdGivesNoIdToItsParts(): void
    {
        $this->mount(self::APP.'FieldWithNoIdDemo')
            ->assertSelectorExists('label[data-phem-field-target="label"]:not([id]):not([for])')
            ->assertSelectorExists('input[data-phem-field-target="control"]:not([id])')
            ->assertSelectorExists('div[data-phem-field-target="error"]:not([id])')
            ->assertSelectorExists('p[data-phem-field-target="description"]:not([id])');
    }

    public function testAnInputOutOfAFieldIsNotAControl(): void
    {
        $this->mount(self::APP.'BoundInputDemo')
            ->assertSelectorExists('input[data-phem-bind][value="Grace"]:not([data-phem-field-target])');
    }

    public function testAFieldControlTakesTheStateOfTheApp(): void
    {
        $this->mount(self::APP.'BoundFieldControlDemo')
            ->assertSelectorExists('input#name-control[data-phem-field-target="control"][data-phem-bind][value="Grace"]');
    }

    public function testTheNumberFieldHasItsParts(): void
    {
        $this->mount(self::APP.'NumberFieldDemo')
            ->assertSelectorExists('[data-controller="phem-number-field"][data-phem-number-field-value-value="100"][data-phem-number-field-min-value="0"]')
            ->assertSelectorExists('[data-phem-number-field-target="scrubArea"] label')
            ->assertSelectorExists('[data-phem-number-field-target="group"] [data-phem-number-field-target="decrement"] + [data-phem-number-field-target="input"] + [data-phem-number-field-target="increment"]')
            ->assertSelectorExists('input[type="hidden"][name="amount"][data-phem-number-field-target="hiddenInput"]');
    }

    public function testTheOtpFieldHasOneInputForEachCharacter(): void
    {
        $this->mount(self::APP.'OTPFieldDemo')
            ->assertSelectorCount(3, '[data-controller="phem-otp-field"] [data-phem-otp-field-target="input"][data-phem-field-target="control"]')
            ->assertSelectorExists('[data-phem-otp-field-normalize-value="uppercase"][data-phem-otp-field-validation-type-value="alphanumeric"]')
            ->assertSelectorExists('input[type="hidden"][name="code"][data-phem-otp-field-target="hiddenInput"]');
    }

    public function testTheSliderPlacesItsThumbsOnTheServer(): void
    {
        $this->mount(self::APP.'SliderDemo')
            ->assertSelectorExists('[data-controller="phem-slider"][data-phem-slider-value-value="[20,60]"]')
            ->assertSelectorTextContains('output[data-phem-slider-target="value"]', '20 – 60')
            ->assertSelectorExists('[data-phem-slider-target="indicator"][style*="inset-inline-start: 20%; width: 40%"]')
            ->assertSelectorExists('[data-phem-slider-target="thumb"][data-index="1"][style*="inset-inline-start: 60%"] input[type="range"][aria-label="Maximum price"][value="60"]')
            ->assertSelectorExists('[data-phem-slider-thumb-alignment-value="edge"][data-phem-slider-thumb-collision-behavior-value="swap"]')
            ->assertSelectorCount(2, 'input[type="range"][name="price[]"]');
    }
}
