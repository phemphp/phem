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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `data-phem-upload="chunked"`: the browser sends the file to the server in chunks (see
 * ChunkStore). The start answers `{id, offset, chunkSize}`, and each chunk `{offset}`. The
 * last answer has the token. The handler gets an UploadedFile.
 *
 * The browser sends a `fingerprint` of the file (a hash of its first and last bytes) with the
 * start: an upload continues only for a file with the same fingerprint.
 */
final class ChunkedUploadMode implements UploadMode
{
    /** The header with the offset of a chunk. */
    public const OFFSET_HEADER = 'X-Phem-Upload-Offset';

    /** The longest fingerprint of a file. */
    private const MAX_FINGERPRINT = 128;

    public function __construct(
        private readonly ChunkStore $store,
        private readonly UploadToken $tokens,
        private readonly int $chunkSize = 2097152,
    ) {
    }

    public function start(string $name, string $type, int $size, array $data): array
    {
        $fingerprint = $data['fingerprint'] ?? null;
        if (!\is_string($fingerprint) || '' === $fingerprint || \strlen($fingerprint) > self::MAX_FINGERPRINT) {
            // No fingerprint: the upload cannot continue after a reload.
            $fingerprint = null;
        }

        // An empty file has no bytes to send: its token is all the upload, and it takes no place in
        // the store until its event.
        if (0 === $size) {
            return ['offset' => 0, 'token' => $this->tokens->sign(['mode' => 'chunked', 'empty' => true, 'name' => $name, 'type' => $type, 'size' => 0])];
        }

        $resume = null !== $fingerprint && \is_string($data['resume'] ?? null) ? $data['resume'] : null;

        try {
            [$id, $offset] = $this->store->start(['name' => $name, 'type' => $type, 'size' => $size, 'fingerprint' => $fingerprint], $resume);
        } catch (\OverflowException $e) {
            throw new HttpException(507, $e->getMessage(), $e, ['Retry-After' => '60']);
        }

        return $this->progress($id, $offset, $size) + ['id' => $id, 'chunkSize' => $this->chunkSize];
    }

    /** The step `X-Phem-Upload: <id>` with `X-Phem-Upload-Offset`: adds the chunk of the body. */
    public function chunk(string $id, Request $request): JsonResponse
    {
        $info = $this->store->info($id);
        if (null === $info) {
            throw new NotFoundHttpException('The upload is not there: it ended, or it is too old.');
        }

        $offset = $request->headers->get(self::OFFSET_HEADER);
        if (null === $offset || 1 !== preg_match('/^\d{1,18}$/', $offset)) {
            throw new BadRequestHttpException(\sprintf('A chunk has the %s header.', self::OFFSET_HEADER));
        }

        // A chunk larger than the chunk size is rejected before the server reads it.
        $length = $request->headers->get('Content-Length');
        if (null !== $length && (int) $length > $this->chunkSize) {
            throw new HttpException(413, \sprintf('A chunk can have a maximum of %d bytes (the phem.upload_chunk_size option).', $this->chunkSize));
        }

        try {
            $offset = $this->store->append($id, (int) $offset, $request->getContent(true));
        } catch (UploadOffsetException $e) {
            // The browser sends again from the offset of the server.
            return UploadController::json(['offset' => $e->offset], 409);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return UploadController::json($this->progress($id, $offset, $info['size']));
    }

    public function file(array $token): UploadedFile
    {
        if (true === ($token['empty'] ?? false)) {
            return $this->store->empty(self::emptyId($token), (string) ($token['name'] ?? ''), (string) ($token['type'] ?? ''));
        }

        $id = (string) ($token['id'] ?? '');
        $info = $this->store->info($id);
        $path = $this->store->path($id);
        clearstatcache(true, $path);
        if (null === $info || filesize($path) !== $info['size']) {
            throw new BadRequestHttpException('The upload is not there: an earlier event took it, or it is too old.');
        }

        // `test: true`: the file did not come from a PHP upload, but move() and isValid() work.
        return new UploadedFile($path, $info['name'], '' !== $info['type'] ? $info['type'] : null, \UPLOAD_ERR_OK, true);
    }

    public function release(array $token): void
    {
        $this->store->remove(true === ($token['empty'] ?? false) ? self::emptyId($token) : (string) ($token['id'] ?? ''));
    }

    /**
     * The id of the empty file of a token in the store, for its event: the same token gives the
     * same id.
     *
     * @param array<string, mixed> $token
     */
    private static function emptyId(array $token): string
    {
        return substr(hash('sha256', json_encode($token, \JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * The offset of an upload, and its token when the upload has all the bytes.
     *
     * @return array<string, mixed>
     */
    private function progress(string $id, int $offset, int $size): array
    {
        if ($offset < $size) {
            return ['offset' => $offset];
        }

        return ['offset' => $offset, 'token' => $this->tokens->sign(['mode' => 'chunked', 'id' => $id, 'size' => $size])];
    }
}
