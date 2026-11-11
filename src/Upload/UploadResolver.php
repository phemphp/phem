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

use Phem\Http\FileTree;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gives the files of the uploads of an event to its handler. The event has a `_phem_uploads[]`
 * part for each file that the browser uploaded before the event (see UploadController), with the
 * JSON `{field, token}`. The field is the name of the file input, as for a file part.
 *
 * The UploadMode of the token gives the file. An UploadedFile goes in the files of the request at
 * its field, so a Symfony form gets it as a regular file. Another file (a DirectFile) is only in
 * `$e->files`. The files of the event, with its uploads, have a maximum size together
 * (`phem.max_upload_size`).
 */
final class UploadResolver
{
    /** The multipart parts of the uploads of an event. */
    public const PART = '_phem_uploads';

    /**
     * @param ContainerInterface $modes the UploadMode services by their name
     */
    public function __construct(
        private readonly UploadToken $tokens,
        private readonly ContainerInterface $modes,
        private readonly int $maxUploadSize = 8388608,
    ) {
    }

    /**
     * Adds the uploaded files to the files of the request.
     *
     * @throws BadRequestHttpException       for a part or a token that is not valid
     * @throws HttpException           (413) when the files of the event are too large together
     */
    public function resolve(Request $request): ResolvedUploads
    {
        $parts = $request->request->all()[self::PART] ?? [];
        if (!\is_array($parts) || [] === $parts) {
            return new ResolvedUploads($request->files->all());
        }

        $files = $request->files->all();
        $size = FileTree::size($files);
        $others = [];
        $uploads = [];
        foreach ($parts as $part) {
            [$field, $data, $mode] = $this->upload($part);
            $file = $mode->file($data);
            $uploads[] = [$mode, $data];
            $size += (int) ($file instanceof UploadedFile ? $file->getSize() : $file->size);

            if ($file instanceof UploadedFile) {
                $files = FileTree::insert($files, $field, $file);
            } else {
                $others[] = [$field, $file];
            }
        }

        if (0 !== $this->maxUploadSize && $size > $this->maxUploadSize) {
            throw new HttpException(413, \sprintf('The files of an event can have a maximum of %d bytes together (the phem.max_upload_size option).', $this->maxUploadSize));
        }

        $request->files->replace($files);

        foreach ($others as [$field, $file]) {
            $files = FileTree::insert($files, $field, $file);
        }

        return new ResolvedUploads($files, $uploads);
    }

    /**
     * The field, the token data and the mode of a part.
     *
     * @return array{string, array<string, mixed>, UploadMode}
     */
    private function upload(mixed $part): array
    {
        $part = \is_string($part) ? json_decode($part, true) : null;
        if (!\is_array($part) || !\is_string($part['field'] ?? null) || '' === $part['field'] || !\is_string($part['token'] ?? null)) {
            throw new BadRequestHttpException(\sprintf('A %s part has the JSON {field, token}.', self::PART));
        }

        $data = $this->tokens->verify($part['token']);
        $mode = \is_string($data['mode'] ?? null) && $this->modes->has($data['mode']) ? $this->modes->get($data['mode']) : null;
        if (null === $data || !$mode instanceof UploadMode) {
            throw new BadRequestHttpException('The token of an upload is not valid, or it expired.');
        }

        return [$part['field'], $data, $mode];
    }
}
