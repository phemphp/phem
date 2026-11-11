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

namespace Phem\Tests\Fixtures;

use Phem\Upload\DirectUpload;
use Phem\Upload\DirectUploader;

/** A storage with presigned URLs, as S3 (see Phem\Upload\DirectUploader). */
final class FakeDirectUploader implements DirectUploader
{
    public function prepare(string $name, string $type, int $size): DirectUpload
    {
        $key = 'uploads/'.$size.'-'.preg_replace('/[^a-z0-9.]+/i', '-', $name);

        return new DirectUpload('https://bucket.example/'.$key.'?signature=1', $key, ['Content-Type' => $type]);
    }
}
