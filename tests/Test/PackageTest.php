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

        self::assertSame(['Acme\\Ui\\Page\\Clicks', 'Acme\\Ui\\Page\\Gallery'], $manifest->pagesOf('acme/ui'));
        self::assertNotContains('Acme\\Ui\\Page\\Gallery', $manifest->pagesOf(''));
        self::assertContains('App\\Page\\PackagePage', $manifest->pagesOf(''));
    }

    public function testATwigTemplateOfTheAppRendersAComponentOfAPackage(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate("{% component 'Acme\\\\Ui\\\\Badge' with {label: 'Twig'} %}")->render();

        self::assertStringContainsString('<span class="badge">Twig</span>', $html);
    }

    public function testATwigErrorOfAPackagePointsToItsSource(): void
    {
        self::bootKernel();

        /** @var Manifest $manifest */
        $manifest = self::getContainer()->get('phem.manifest');
        $projectDir = self::$kernel->getProjectDir();
        $error = new \RuntimeException('outer', 0, new RuntimeError('Boom.', 1, new Source('', '@phpm/vendor/acme/ui/Badge.0.twig')));

        (new SourceMapListener(new SourceMap($manifest, $projectDir)))->map($error);

        $twigError = $error->getPrevious();
        self::assertInstanceOf(RuntimeError::class, $twigError);
        self::assertSame('vendor/acme/ui/components/Badge.phpm', $twigError->getSourceContext()?->getName());
        self::assertSame($projectDir.'/vendor/acme/ui/components/Badge.phpm', $twigError->getFile());
    }
}
