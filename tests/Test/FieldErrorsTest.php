<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Tests\Test;

use Dom\HTMLDocument;
use Phem\Test\ComponentTester;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The errors of the bound fields: `aria-invalid`, `aria-describedby` and `phem:error`
 * (tests/Fixtures/app/src/Ui/Validation/FieldErrorsPage.phem).
 */
final class FieldErrorsTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAFieldWithNoErrorHasNoAriaAttributesAndNoMessage(): void
    {
        $page = $this->visit('/validation/errors')
            ->assertSelectorNotExists('[aria-invalid]')
            ->assertSelectorNotExists('.message')
            ->assertSelectorNotExists('.city-error')
            ->assertSelectorNotExists('em');

        self::assertNull(self::attr($page, 'input[name=email]', 'aria-describedby'));
        self::assertSame('city-help', self::attr($page, 'input[name=city]', 'aria-describedby'), 'the ids of the markup stay');
    }

    public function testAnErrorGivesTheFieldAndTheMessageTheSameId(): void
    {
        $page = $this->visit('/validation/errors')
            ->change('input[name=email]', 'ada')
            ->assertSelectorExists('input[name=email][aria-invalid=true]')
            ->assertSelectorTextContains('span.message', 'This value is not a valid email address.');

        $id = self::attr($page, 'span.message', 'id');
        self::assertMatchesRegularExpression('/^phem[0-9a-f]{12}-email-error$/', (string) $id);
        self::assertSame($id, self::attr($page, 'input[name=email]', 'aria-describedby'));

        // The other fields have no error.
        $page->assertSelectorNotExists('input[name=city][aria-invalid]')
            ->assertSelectorNotExists('.city-error');
    }

    public function testTheIdOfTheErrorGoesAfterTheIdsOfTheMarkup(): void
    {
        $page = $this->visit('/validation/errors')
            ->change('input[name=city]', 'Rome')
            ->change('input[name=city]', '')
            ->assertSelectorExists('input[name=city][aria-invalid=true]')
            ->assertSelectorTextContains('p.city-error', 'Enter a city.');

        $id = (string) self::attr($page, 'p.city-error', 'id');
        self::assertStringEndsWith('-address-city-error', $id);
        self::assertSame('city-help '.$id, self::attr($page, 'input[name=city]', 'aria-describedby'));
    }

    public function testAFixedValueRemovesTheMessageAndTheAriaAttributes(): void
    {
        $page = $this->visit('/validation/errors')
            ->change('input[name=email]', 'ada')
            ->change('input[name=email]', 'ada@example.com')
            ->assertSelectorNotExists('[aria-invalid]')
            ->assertSelectorNotExists('.message');

        self::assertNull(self::attr($page, 'input[name=email]', 'aria-describedby'));
    }

    public function testTheFieldOfAChildShowsTheErrorOfTheParent(): void
    {
        $page = $this->visit('/validation/errors')
            ->submit('form')
            ->assertSelectorExists('input[name=name][aria-invalid=true]')
            ->assertSelectorTextContains('em', 'Enter a name.')
            ->assertSelectorTextContains('span.message', 'This value should not be blank.');

        $id = (string) self::attr($page, 'em', 'id');
        self::assertStringEndsWith('-name-error', $id);
        self::assertSame($id, self::attr($page, 'input[name=name]', 'aria-describedby'));
        self::assertStringStartsWith(explode('-', (string) self::attr($page, 'span.message', 'id'))[0].'-', $id, 'the fiber of the parent, the owner of the property');
    }

    public function testTheAttributesOfASpreadDoNotHideTheError(): void
    {
        $page = $this->visit('/validation/errors');
        self::assertSame('phone-help', self::attr($page, 'input[name=phone]', 'aria-describedby'));

        $page->submit('form')
            ->assertSelectorExists('input[name=phone][aria-invalid=true]')
            ->assertSelectorTextContains('p.phone-error', 'Enter a phone.')
            ->assertSelectorNotExists('#phone-note');

        $id = (string) self::attr($page, 'p.phone-error', 'id');
        self::assertStringEndsWith('-phone-error', $id);
        self::assertSame('phone-help '.$id, self::attr($page, 'input[name=phone]', 'aria-describedby'));
    }

    private static function attr(ComponentTester $page, string $selector, string $name): ?string
    {
        $document = HTMLDocument::createFromString($page->html(), \LIBXML_NOERROR, 'UTF-8');
        $element = $document->querySelector($selector) ?? self::fail(\sprintf('No element matches "%s".', $selector));

        return $element->getAttribute($name);
    }
}
