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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A mode of `data-phem-upload`: how the browser sends a file before its event. The service has
 * the `phem.upload_mode` tag with the name of the mode (`chunked`, `direct`), as the attribute
 * has it.
 *
 * The browser starts the upload (see UploadController), and the event sends the token of the
 * file in its place (see UploadResolver).
 */
interface UploadMode
{
    /**
     * The answer to the start of an upload. When the server has the file, the answer has the
     * `token` of the file (see UploadToken), with `mode` in its data.
     *
     * @param string               $name the name of the file in the browser
     * @param string               $type the media type that the browser gives, or an empty string
     * @param int                  $size the size in bytes (at most `phem.max_upload_size`)
     * @param array<string, mixed> $data the JSON of the browser, for the options of the mode
     *
     * @return array<string, mixed>
     *
     * @throws HttpExceptionInterface when the server does not take the upload
     */
    public function start(string $name, string $type, int $size, array $data): array;

    /**
     * The file of a token for the handler.
     *
     * @param array<string, mixed> $token the data of a valid token of this mode
     *
     * @throws HttpExceptionInterface when the file is not there
     */
    public function file(array $token): UploadedFile|DirectFile;

    /**
     * Ends the upload after its event, as PHP removes an uploaded file at the end of the request.
     *
     * @param array<string, mixed> $token
     */
    public function release(array $token): void;
}
