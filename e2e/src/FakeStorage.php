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

namespace App;

use Phem\Upload\DirectUpload;
use Phem\Upload\DirectUploader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A storage with presigned URLs, as S3, for the direct uploads of UploadPage: the browser sends
 * the file with `PUT /storage/{key}`, and the handler reads it with read().
 */
final class FakeStorage implements DirectUploader
{
    public function __construct(private readonly string $dir)
    {
    }

    public function prepare(string $name, string $type, int $size): DirectUpload
    {
        $key = bin2hex(random_bytes(8));

        return new DirectUpload('http://localhost:8000/storage/'.$key.'?signature=e2e', $key, ['Content-Type' => $type]);
    }

    /** The PUT of the browser. */
    public function __invoke(Request $request, string $key): Response
    {
        if ('e2e' !== $request->query->get('signature') || 1 !== preg_match('/^[0-9a-f]{16}$/', $key)) {
            return new Response('', 403);
        }

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o777, true);
        }

        file_put_contents($this->dir.'/'.$key, $request->getContent());

        return new Response('', 200);
    }

    /** The bytes of an object, or null. */
    public function read(string $key): ?string
    {
        $path = $this->dir.'/'.basename($key);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
