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
 * A chunk that does not start at the end of the upload (see ChunkStore::append()): an earlier
 * chunk did not arrive, or it arrived two times. The browser sends again from `$offset`.
 */
final class UploadOffsetException extends \RuntimeException
{
    public function __construct(public readonly int $offset)
    {
        parent::__construct(\sprintf('The next chunk of the upload starts at byte %d.', $offset));
    }
}
