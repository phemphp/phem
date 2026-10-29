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

use Phem\Manifest\Manifest;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\Package\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tests of a package with Phem\Test\TestKernel (tests/Fixtures/Package): a package repo with
 * a test app in tests/app/src, and no app of its own.
 */
final class PackageKernelTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTheProjectDirIsTheRootOfThePackage(): void
    {
        self::bootKernel();

        self::assertSame(\dirname(__DIR__).'/Fixtures/Package', self::$kernel?->getProjectDir());
    }

    public function testTheTestAppUsesTheComponentsOfThePackage(): void
    {
        $this->visit('/')->assertSelectorTextContains('#home button.kit', 'Go');
    }

    public function testATestMountsAComponentOfThePackage(): void
    {
        $this->mount('Acme\Kit\Button', ['label' => 'Save'])->assertSelectorTextContains('button.kit', 'Save');
    }

    public function testThePackageCompilesAsAPackage(): void
    {
        self::bootKernel();

        /** @var Manifest $manifest */
        $manifest = self::getContainer()->get('phem.manifest');

        // Its pages need a route import, as in an app.
        self::assertSame(['Acme\Kit\Page\Demo'], $manifest->pagesOf('acme/kit'));
        self::assertSame(['Acme\Kit\Tests\Page\Home'], $manifest->pagesOf(''));
        self::assertSame('vendor/acme/kit/Button.0.twig', $manifest->component('Acme\Kit\Button')?->template);
    }

    public function testThePagesOfThePackageHaveTheRoutesOfTheImport(): void
    {
        $this->visit('/kit/demo')->assertSelectorTextContains('#demo button.kit', 'Demo');
        $this->visit('/demo')->assertStatus(404);
    }
}
