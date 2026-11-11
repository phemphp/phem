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

namespace Phem\Test\Internal;

use Phem\Http\FileTree;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The files of a test event as the files of a request: UploadedFile objects, in the shape that
 * PHP gives them (`photos[]` gives a list, `scientist[photo]` an array).
 *
 * Each file is a copy in the temporary directory, so a handler that moves it does not move the
 * file of the test.
 *
 * @internal
 */
final class TestFiles
{
    /**
     * @param list<array{0: string, 1: string}> $files [field name, path] pairs
     *
     * @return array<mixed>
     */
    public static function of(array $files): array
    {
        $tree = [];
        foreach ($files as [$name, $path]) {
            $tree = FileTree::insert($tree, $name, self::copy($path));
        }

        return $tree;
    }

    private static function copy(string $path): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'phem');
        if (false === $copy || !copy($path, $copy)) {
            throw new \RuntimeException(\sprintf('Cannot copy the file "%s" for the test.', $path));
        }

        register_shutdown_function(static function () use ($copy): void {
            if (is_file($copy)) {
                unlink($copy);
            }
        });

        // `test: true`: the file did not come from a PHP upload, but move() and isValid() work.
        return new UploadedFile($copy, basename($path), null, \UPLOAD_ERR_OK, true);
    }
}
