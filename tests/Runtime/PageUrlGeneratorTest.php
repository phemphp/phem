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

namespace Phem\Tests\Runtime;

use Phem\Manifest\Manifest;
use Phem\RedirectTo;
use Phem\Routing\PageLoader;
use Phem\Routing\PageUrlGenerator;
use Phem\Tests\Fixtures\PathPages\Known;
use Phem\Tests\Fixtures\PathPages\Paged;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;

use function Phem\Tests\Fixtures\PathPages\FunctionPage;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PageUrlGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-url-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testUrlOfAClassPage(): void
    {
        self::assertSame('/paged', $this->urls()->generate(Paged::class));
        self::assertSame('/paged', $this->urls()->generate('\\'.Paged::class), 'a leading backslash');
    }

    public function testUrlOfAFunctionPage(): void
    {
        self::assertSame('/function', $this->urls()->generate(FunctionPage(...)));
    }

    public function testValuesGoToThePathAndTheQueryString(): void
    {
        self::assertSame('/known/5?tab=notes#top', $this->urls()->generate(Known::class, ['id' => 5, 'tab' => 'notes', '_fragment' => 'top']));
        self::assertSame('/known', $this->urls()->generate(Known::class), 'the default of the route');
    }

    public function testRedirectResponseOfARedirect(): void
    {
        self::assertSame('/known/7', $this->urls()->redirect(new RedirectTo(Known::class, ['id' => 7]))->getTargetUrl());
    }

    public function testComponentThatIsNotAPageIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"App\Missing" is not a page: add #[Page] to it, and run `phemc build`.');

        $this->urls()->generate('App\\Missing');
    }

    public function testAnonymousClosureIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A page is a class name, or the first-class callable of a function page');

        $this->urls()->generate(static fn (): null => null);
    }

    private function urls(): PageUrlGenerator
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [Paged::class => '', Known::class => '', 'Phem\\Tests\\Fixtures\\PathPages\\FunctionPage' => '']], true).';');
        $routes = (new PageLoader(new Manifest($this->dir)))->load('.', 'phem');

        return new PageUrlGenerator(new UrlGenerator($routes, new RequestContext()));
    }
}
