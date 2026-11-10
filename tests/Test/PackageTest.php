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

use Phem\EventListener\SourceMapListener;
use Phem\Manifest\Manifest;
use Phem\Manifest\SourceMap;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Source;

/**
 * The components of a Composer package (tests/Fixtures/app/vendor/acme/ui): phemc compiles them
 * with the app, and the app imports the routes of the pages of the package.
 */
final class PackageTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAPageOfTheAppUsesAComponentOfAPackage(): void
    {
        $this->visit('/package')
            ->assertSelectorTextContains('#package .badge', 'New');
    }

    public function testThePagesOfAPackageHaveTheRoutesThatTheAppImports(): void
    {
        $this->visit('/ui/gallery')
            ->assertStatus(200)
            ->assertSelectorCount(2, '#gallery .badge')
            ->assertSee('Sale');
    }

    public function testAnEventOfAPackagePageGoesToItsPrefixedUrl(): void
    {
        $this->visit('/ui/clicks')
            ->click('#add')
            ->click('#add')
            ->assertSelectorTextContains('#add', 'Clicks 2')
            ->assertState('count', 2);
    }

    public function testThePagesOfAPackageAreNotRoutesOfTheApp(): void
    {
        $this->visit('/gallery')->assertStatus(404);
    }

    public function testTheManifestKeepsThePackageOfEachPage(): void
    {
        self::bootKernel();

        /** @var Manifest $manifest */
        $manifest = self::getContainer()->get('phem.manifest');

        self::assertSame(['Acme\\Ui\\ClicksPage', 'Acme\\Ui\\GalleryPage'], $manifest->pagesOf('acme/ui'));
        self::assertNotContains('Acme\\Ui\\GalleryPage', $manifest->pagesOf(''));
        self::assertContains('App\\Ui\\Package\\PackagePage', $manifest->pagesOf(''));
    }

    public function testATwigTemplateOfTheAppRendersAComponentOfAPackage(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate("{% component 'Acme\\\\Ui\\\\Badge' with {label: 'Twig'} %}")->render();

        self::assertStringContainsString('<span class="badge">Twig</span>', $html);
    }

    public function testAReplacementRendersInTheTagsOfTheAppAndOfThePackage(): void
    {
        $this->visit('/replaced')
            ->assertSelectorCount(2, '#replaced .alert')
            ->assertSelectorTextContains('#replaced > .alert-info', 'Note Saved')
            ->assertSelectorTextContains('#replaced .notice > .alert-warning', 'Careful Read this');
    }

    public function testThePagesOfAPackageRenderInTheLayoutOfThePackage(): void
    {
        $this->visit('/ui/gallery')
            ->assertSelectorExists('body > #phem-root #gallery')
            ->assertSelectorTextContains('title', 'Acme UI');
    }

    public function testATwigTemplateOfTheAppRendersTheReplacement(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate("{% component 'Acme\\\\Ui\\\\Alert' with {type: 'warning'} %}")->render();

        self::assertStringContainsString('<strong>Careful</strong>', $html);
        self::assertSame(1, substr_count($html, 'role="alert"'), 'the replacement renders the original once');
    }

    public function testATwigTemplateOfTheAppRendersTheReplacementWithSlots(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate("{% component embed 'Acme\\\\Ui\\\\Alert' %}{% block children %}Body{% endblock %}{% endcomponent %}")->render();

        self::assertStringContainsString('<strong>Note</strong> Body', $html);
        self::assertSame(1, substr_count($html, 'role="alert"'));
    }

    public function testAnyTwigTemplateThatPhemcDidNotCompileRendersTheReplacement(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $code = "{% component 'Acme\\\\Ui\\\\Alert' with {type: 'warning'} %}";

        // A plain Twig template of a package, in its own namespace.
        self::assertStringContainsString('<strong>Careful</strong>', $twig->createTemplate($code, '@AcmeUi/notice.html.twig')->render());
        // A template of phemc keeps its tag: phemc already resolved it.
        self::assertStringNotContainsString('<strong>', $twig->createTemplate($code, '@'.Manifest::TEMPLATE_NAMESPACE.'/Ui/Common/Alert.9.twig')->render());
    }

    public function testPhemBindOnATagOfThePackageGoesThroughTheReplacementToTheOriginal(): void
    {
        $this->visit('/replaced')
            ->assertSelectorExists('#newsletter label.my-field input[name=email]')
            ->submit('#newsletter', ['email' => 'ada@example.com'])
            ->assertSelectorTextContains('#newsletter-email', 'ada@example.com, saves 1')
            ->assertState('email', 'ada@example.com', '#newsletter');
    }

    public function testAClassReplacementKeepsItsStateAndTheStateOfTheOriginal(): void
    {
        $this->visit('/replaced')
            ->assertSelectorTextContains('#my-toggle #flip', 'Lamp off')
            ->click('#count')
            ->assertSelectorTextContains('#count', 'Flips 1')
            ->click('#flip')
            ->assertSelectorTextContains('#my-toggle #flip', 'Lamp on')
            ->assertSelectorTextContains('#count', 'Flips 1')
            ->click('#count')
            ->assertSelectorTextContains('#count', 'Flips 2')
            ->assertSelectorTextContains('#flip', 'Lamp on');
    }

    public function testTheManifestKeepsTheReplacements(): void
    {
        self::bootKernel();

        /** @var Manifest $manifest */
        $manifest = self::getContainer()->get('phem.manifest');

        self::assertSame('App\\Ui\\Common\\Alert', $manifest->replacement('Acme\\Ui\\Alert'));
        self::assertSame('App\\Ui\\Common\\Alert', $manifest->replacement('\\Acme\\Ui\\Alert'), 'with a leading backslash');
        self::assertNull($manifest->replacement('Acme\\Ui\\Badge'));
    }

    public function testATwigErrorOfAPackagePointsToItsSource(): void
    {
        $kernel = self::bootKernel();

        /** @var Manifest $manifest */
        $manifest = self::getContainer()->get('phem.manifest');
        $projectDir = $kernel->getProjectDir();
        $error = new \RuntimeException('outer', 0, new RuntimeError('Boom.', 1, new Source('', '@phemc/vendor/acme/ui/Badge.0.twig')));

        (new SourceMapListener(new SourceMap($manifest, $projectDir)))->map($error);

        $twigError = $error->getPrevious();
        self::assertInstanceOf(RuntimeError::class, $twigError);
        self::assertSame('vendor/acme/ui/components/Badge.phem', $twigError->getSourceContext()?->getName());
        self::assertSame($projectDir.'/vendor/acme/ui/components/Badge.phem', $twigError->getFile());
    }
}
