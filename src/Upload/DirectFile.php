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
 * A file that the browser uploaded to a storage before its event (see DirectUploader). The handler
 * gets it in `$e->files`, as it gets an UploadedFile. The file is not on the server.
 *
 * The browser sent the file, so check it before you keep it: the storage has the real size and
 * type. The key comes from DirectUploader::prepare(): the browser cannot change it.
 */
final class DirectFile
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $type,
        public readonly int $size,
    ) {
    }
}
