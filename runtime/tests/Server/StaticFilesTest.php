<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Rephp\Server\Request;
use Rephp\Server\StaticFiles;

final class StaticFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rephp-static-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/public/docs', 0o777, true);
        mkdir($this->dir.'/assets');
        mkdir($this->dir.'/site/learn', 0o777, true);
        file_put_contents($this->dir.'/site/learn/index.html', '<h1>Learn</h1>');
        file_put_contents($this->dir.'/site/learn/Counter.rhp', '<button>{{ count }}</button>');
        file_put_contents($this->dir.'/public/app.css', 'body{}');
        file_put_contents($this->dir.'/public/index.html', '<h1>Home</h1>');
        file_put_contents($this->dir.'/public/docs/index.html', '<h1>Docs</h1>');
        file_put_contents($this->dir.'/public/.env', 'SECRET=1');
        file_put_contents($this->dir.'/assets/turbo.js', 'export {}');
        file_put_contents($this->dir.'/secret.txt', 'top secret');
        symlink($this->dir.'/secret.txt', $this->dir.'/public/link.txt');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testServesFileWithContentTypeAndCacheHeaders(): void
    {
        $response = $this->files()->serve(new Request('GET', '/app.css'));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('body{}', $response->body);
        self::assertSame('text/css; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('no-cache', $response->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/^"[0-9a-f]+-6"$/', $response->headers['ETag']);
    }

    public function testLongestPrefixWins(): void
    {
        $response = $this->files()->serve(new Request('GET', '/_rhp/assets/turbo.js'));

        self::assertSame('export {}', $response?->body);
        self::assertSame('text/javascript; charset=UTF-8', $response->headers['Content-Type']);
    }

    public function testIfNoneMatchGives304(): void
    {
        $etag = $this->files()->serve(new Request('GET', '/app.css'))?->headers['ETag'];

        $response = $this->files()->serve(new Request('GET', '/app.css', headers: ['if-none-match' => 'W/"other", '.$etag]));

        self::assertSame(304, $response?->status);
        self::assertSame('', $response->body);
        self::assertSame($etag, $response->headers['ETag']);
    }

    public function testDirectoryServesIndexHtml(): void
    {
        self::assertSame('<h1>Home</h1>', $this->files()->serve(new Request('GET', '/'))?->body);
        self::assertSame('<h1>Docs</h1>', $this->files()->serve(new Request('GET', '/docs/'))?->body);
        self::assertSame('<h1>Docs</h1>', $this->files()->serve(new Request('HEAD', '/docs'))?->body);
    }

    public function testDirectoryUnderNonRootMountServesIndexHtml(): void
    {
        $files = new StaticFiles(['/docs/' => $this->dir.'/site', '/' => $this->dir.'/public']);

        foreach (['/docs/learn', '/docs/learn/', '/docs/learn/index.html'] as $path) {
            $response = $files->serve(new Request('GET', $path));

            self::assertSame(200, $response?->status, $path);
            self::assertSame('<h1>Learn</h1>', $response->body, $path);
            self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type'], $path);
        }

        $source = $files->serve(new Request('GET', '/docs/learn/Counter.rhp'));
        self::assertSame('text/plain; charset=UTF-8', $source?->headers['Content-Type']);
        self::assertSame('<button>{{ count }}</button>', $source->body);
    }

    public function testDirectoryWithoutIndexHtmlFallsThrough(): void
    {
        self::assertNull((new StaticFiles(['/' => $this->dir]))->serve(new Request('GET', '/assets')));
    }

        public function testTraversalOutsideTheDirectoryIsBlocked(): void
    {
        $files = $this->files();

        self::assertNull($files->serve(new Request('GET', '/../secret.txt')));
        self::assertNull($files->serve(new Request('GET', '/docs/../../secret.txt')));
        self::assertNull($files->serve(new Request('GET', '/_rhp/assets/../public/app.css')));
        self::assertNull($files->serve(new Request('GET', '/link.txt')), 'A symlink that leaves the root is blocked.');
        self::assertNull($files->serve(new Request('GET', '/.env')), 'Hidden files are not served.');
    }

    public function testFallsThroughWhenNoFileExists(): void
    {
        $files = $this->files();

        self::assertNull($files->serve(new Request('GET', '/counter')));
        self::assertNull($files->serve(new Request('GET', '/_rhp/assets/missing.js')));
        self::assertNull($files->serve(new Request('POST', '/app.css')), 'Only GET and HEAD serve files.');
    }

    public function testMissingMountDirectoryIsIgnored(): void
    {
        $files = new StaticFiles(['/' => $this->dir.'/does-not-exist']);

        self::assertNull($files->serve(new Request('GET', '/app.css')));
    }

    private function files(): StaticFiles
    {
        return new StaticFiles([
            '/' => $this->dir.'/public',
            '/_rhp/assets/' => $this->dir.'/assets',
        ]);
    }
}
