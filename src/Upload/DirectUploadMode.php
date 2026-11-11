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

namespace Phem\Upload;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `data-phem-upload="direct"`: the browser sends the file to a storage, with the request of the
 * DirectUploader of the application. The start answers `{url, method, headers, token}`. The
 * handler gets a DirectFile.
 */
final class DirectUploadMode implements UploadMode
{
    public function __construct(
        private readonly UploadToken $tokens,
        private readonly ?DirectUploader $uploader = null,
    ) {
    }

    public function start(string $name, string $type, int $size, array $data): array
    {
        if (null === $this->uploader) {
            throw new BadRequestHttpException(\sprintf('A direct upload needs a service that implements %s.', DirectUploader::class));
        }

        $upload = $this->uploader->prepare($name, $type, $size);

        return [
            'url' => $upload->url,
            'method' => $upload->method,
            'headers' => (object) $upload->headers,
            'token' => $this->tokens->sign(['mode' => 'direct', 'key' => $upload->key, 'name' => $name, 'type' => $type, 'size' => $size]),
        ];
    }

    public function file(array $token): DirectFile
    {
        return new DirectFile((string) ($token['key'] ?? ''), (string) ($token['name'] ?? ''), (string) ($token['type'] ?? ''), (int) ($token['size'] ?? 0));
    }

    public function release(array $token): void
    {
        // The file is in the storage of the application: the handler keeps it or removes it.
    }
}
