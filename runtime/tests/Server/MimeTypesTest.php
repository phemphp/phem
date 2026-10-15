<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephp\Server\MimeTypes;

final class MimeTypesTest extends TestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function types(): iterable
    {
        yield ['index.html', 'text/html; charset=UTF-8'];
        yield ['app.css', 'text/css; charset=UTF-8'];
        yield ['app.js', 'text/javascript; charset=UTF-8'];
        yield ['module.MJS', 'text/javascript; charset=UTF-8'];
        yield ['data.json', 'application/json'];
        yield ['logo.svg', 'image/svg+xml'];
        yield ['a.png', 'image/png'];
        yield ['a.jpg', 'image/jpeg'];
        yield ['a.jpeg', 'image/jpeg'];
        yield ['a.gif', 'image/gif'];
        yield ['a.webp', 'image/webp'];
        yield ['favicon.ico', 'image/x-icon'];
        yield ['font.woff', 'font/woff'];
        yield ['font.woff2', 'font/woff2'];
        yield ['robots.txt', 'text/plain; charset=UTF-8'];
        yield ['app.js.map', 'application/json'];
        yield ['Counter.rhp', 'text/plain; charset=UTF-8'];
        yield ['archive.tar.gz', 'application/octet-stream'];
        yield ['Makefile', 'application/octet-stream'];
    }

    #[DataProvider('types')]
    public function testForPath(string $path, string $expected): void
    {
        self::assertSame($expected, MimeTypes::forPath('/var/www/'.$path));
    }
}
