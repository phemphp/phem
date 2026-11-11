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

/**
 * The request that uploads a file from the browser to a storage (see DirectUploader): the browser
 * sends the file as the body, with these headers. The `key` names the file in the storage: the
 * handler gets it in DirectFile::$key.
 */
final class DirectUpload
{
    /**
     * @param array<string, string> $headers the headers of the request, for example the `Content-Type` of a presigned URL
     */
    public function __construct(
        public readonly string $url,
        public readonly string $key,
        public readonly array $headers = [],
        public readonly string $method = 'PUT',
    ) {
    }
}
