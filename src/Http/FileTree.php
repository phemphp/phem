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

namespace Phem\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts a file at the keys of its field name, as PHP does for a multipart body: `photo`,
 * `photos[]` (an empty key adds an item) or `doc[file]`. The result has the shape of
 * `$request->files->all()`.
 */
final class FileTree
{
    /**
     * The size of the uploaded files of a tree, together. A file with an upload error (larger
     * than `upload_max_filesize` of PHP) has no content: it does not count.
     *
     * @param array<mixed> $tree
     */
    public static function size(array $tree): int
    {
        $size = 0;
        array_walk_recursive($tree, static function (mixed $file) use (&$size): void {
            if ($file instanceof UploadedFile && \UPLOAD_ERR_OK === $file->getError()) {
                $size += (int) $file->getSize();
            }
        });

        return $size;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    public static function insert(array $tree, string $name, mixed $file): array
    {
        $base = str_starts_with($name, '[') ? $name : explode('[', $name, 2)[0];
        preg_match_all('/\[([^\]]*)\]/', substr($name, \strlen($base)), $m);

        return self::insertAt($tree, [$base, ...$m[1]], $file);
    }

    /**
     * @param array<mixed>           $tree
     * @param non-empty-list<string> $keys
     *
     * @return array<mixed>
     */
    private static function insertAt(array $tree, array $keys, mixed $file): array
    {
        $key = array_shift($keys);
        $value = [] === $keys ? $file : self::insertAt('' !== $key && \is_array($tree[$key] ?? null) ? $tree[$key] : [], $keys, $file);

        if ('' === $key) {
            $tree[] = $value;
        } else {
            $tree[$key] = $value;
        }

        return $tree;
    }
}
