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
 * The files of an event with its uploads (see UploadResolver): the files of the request and the
 * files of the uploads, in the shape of `$request->files->all()`.
 */
final class ResolvedUploads
{
    /**
     * @param array<mixed>                                  $files   the files for Phem\Event::$files
     * @param list<array{UploadMode, array<string, mixed>}> $uploads the uploads of the event: their mode and the data of their token
     */
    public function __construct(
        public readonly array $files,
        private readonly array $uploads = [],
    ) {
    }

    /**
     * Ends the uploads after the event, as PHP removes an uploaded file at the end of the
     * request. A file that the handler moved stays.
     */
    public function release(): void
    {
        foreach ($this->uploads as [$mode, $data]) {
            $mode->release($data);
        }
    }
}
