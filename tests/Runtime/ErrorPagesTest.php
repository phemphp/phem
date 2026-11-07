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

use Phem\Http\ErrorPages;
use Phem\Http\ErrorPagesWarmer;
use Phem\Manifest\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__).'/Fixtures/ErrorPageFunctions.php';

final class ErrorPagesTest extends TestCase
{
    private const NS = 'Phem\\Tests\\Fixtures\\ErrorPageFunctions\\';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-errors-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function errors(): iterable
    {
        yield 'a page with the status' => [404, '/missing', 'NotFound'];
        yield 'a page of all the statuses' => [500, '/cart', 'Everything'];
        yield 'a page with a list of statuses' => [403, '/cart', 'Denied'];
        yield 'the longest path, then the status' => [404, '/admin/users', 'AdminNotFound'];
        yield 'the longest path wins over the status' => [500, '/admin', 'Admin'];
        yield 'a path is a full segment' => [500, '/administration', 'Everything'];
        yield 'a repeated attribute' => [500, '/blog/a-post', 'Sections'];
    }

    #[DataProvider('errors')]
    public function testThePageOfAnError(int $status, string $path, string $page): void
    {
        $pages = $this->pages('Everything', 'NotFound', 'Denied', 'Admin', 'AdminNotFound', 'Sections');

        self::assertSame(self::NS.$page, $pages->find($status, $path));
    }

    public function testNoPageMatches(): void
    {
        self::assertNull($this->pages('NotFound', 'Admin')->find(500, '/cart'));
        self::assertNull($this->pages()->find(404, '/'));
    }

    public function testTheChoiceSkipsAnAttributeThatIsNotValid(): void
    {
        $pages = $this->pages('Redirect', 'Relative', 'Typo', 'Gone', 'NotFound', 'AlsoNotFound', 'Everything');

        self::assertSame(self::NS.'NotFound', $pages->find(404, '/'), 'the first page of a status stays');
        self::assertSame(self::NS.'Everything', $pages->find(500, '/'), 'the other pages still show');
        self::assertSame(self::NS.'Everything', $pages->find(410, '/'), 'a page with a duplicate status is skipped');
    }

    public function testCheckReportsAllTheAttributesThatAreNotValid(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(implode("\n", [
            'The #[ErrorPage] of "Phem\Tests\Fixtures\ErrorPageFunctions\Redirect" is not valid: the status 302 is not an error status (400 to 599).',
            'The #[ErrorPage] of "Phem\Tests\Fixtures\ErrorPageFunctions\Relative" is not valid: the path "admin" does not start with "/".',
            'The #[ErrorPage] of "Phem\Tests\Fixtures\ErrorPageFunctions\Typo" is not valid: Unknown named parameter $paths',
            'The error page "Phem\Tests\Fixtures\ErrorPageFunctions\Gone" is not loaded. Run `phemc build` again.',
            '"Phem\Tests\Fixtures\ErrorPageFunctions\NotFound" and "Phem\Tests\Fixtures\ErrorPageFunctions\AlsoNotFound" are both the error page of the status 404 at "/". Remove one #[ErrorPage], or give it another status or path.',
        ]));

        $this->pages('Redirect', 'Relative', 'Typo', 'Gone', 'NotFound', 'AlsoNotFound')->check();
    }

    public function testCheckPassesForValidPages(): void
    {
        $this->expectNotToPerformAssertions();

        $this->pages('Everything', 'NotFound', 'Denied', 'Admin', 'AdminNotFound', 'Sections')->check();
    }

    public function testTheWarmerChecksThePagesWhenTheContainerBuilds(): void
    {
        $pages = $this->pages('Redirect');
        $warmer = new ErrorPagesWarmer($pages, new Manifest($this->dir));

        self::assertFalse($warmer->isOptional(), 'Symfony runs it after each build of the container');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the status 302 is not an error status');

        $warmer->warmUp($this->dir);
    }

    public function testTheWarmerDoesNothingWithNoBuild(): void
    {
        $manifest = new Manifest($this->dir.'/missing');

        self::assertSame([], (new ErrorPagesWarmer(new ErrorPages($manifest), $manifest))->warmUp($this->dir));
    }

    private function pages(string ...$names): ErrorPages
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['error_pages' => array_map(static fn (string $n): string => self::NS.$n, $names)], true).';');

        return new ErrorPages(new Manifest($this->dir));
    }
}
