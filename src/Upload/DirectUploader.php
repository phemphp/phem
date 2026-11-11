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
 * Prepares the direct upload of a file from the browser to a storage, for example a presigned
 * `PUT` URL of S3. The file does not go through the server. An input with
 * `data-phem-upload="direct"` uses it, and the handler gets a DirectFile.
 *
 * The application implements it. Phem finds the service by this interface.
 */
interface DirectUploader
{
    /**
     * @param string $name the name of the file in the browser. Do not use it as the key as it is: the user chooses it
     * @param string $type the media type that the browser gives, or an empty string
     * @param int    $size the size in bytes (at most `phem.max_upload_size`)
     */
    public function prepare(string $name, string $type, int $size): DirectUpload;
}
