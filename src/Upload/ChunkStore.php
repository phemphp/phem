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

/**
 * The files of the chunked uploads, in a directory of the server (`phem.upload_dir`). The browser
 * sends a large file in chunks, one request each, so a file can be larger than `post_max_size`.
 * After a network error, or a reload of the page, the upload continues at the end of the
 * last chunk that arrived.
 *
 * Each upload has a random id of 32 hex characters, and two files: `<id>.part` with the bytes,
 * and `<id>.json` with the name, the media type, the size and the fingerprint that the browser
 * declared. All the servers of an application must share the directory. The store removes the
 * uploads that did not change for MAX_AGE seconds when a new upload starts, and the uploads that
 * got no chunk for IDLE_AGE seconds. Against a full disk, the uploads in the store have a maximum
 * size together (`phem.upload_dir_max_size`), where each one counts at least one chunk, and a
 * maximum number (`phem.upload_dir_max_files`).
 */
final class ChunkStore
{
    /** The seconds that an upload with no new chunk stays (the same as an UploadToken). */
    public const MAX_AGE = UploadToken::MAX_AGE;

    /** The seconds that an upload with no chunk at all stays: a start with no bytes reserves nothing for long. */
    public const IDLE_AGE = 900;

    private const ID = '/^[0-9a-f]{32}$/';

    /**
     * @param int $maxSize  the maximum of the sizes of the uploads in the store together, in bytes (0 for no limit)
     * @param int $minSize  the size that an upload counts at least, in bytes (the chunk size): many small uploads also fill the limit
     * @param int $maxCount the maximum number of uploads in the store (0 for no limit)
     */
    public function __construct(
        private readonly string $dir,
        private readonly int $maxSize = 0,
        private readonly int $minSize = 0,
        private readonly int $maxCount = 0,
    ) {
    }

    /**
     * Starts an upload, or continues the upload `$resume` when it has the same name, type, size
     * and fingerprint. An upload with no fingerprint does not continue.
     *
     * @param array{name: string, type: string, size: int, fingerprint: string|null} $info what the browser declares
     *
     * @return array{string, int} the id of the upload and the bytes that the store has
     *
     * @throws \OverflowException when the uploads in the store would be larger than its maximum
     *                            size, or more than its maximum number
     */
    public function start(array $info, ?string $resume = null): array
    {
        if (null !== $resume && null !== $info['fingerprint'] && $info === $this->info($resume)) {
            clearstatcache(true, $this->path($resume));

            return [$resume, (int) filesize($this->path($resume))];
        }

        // The uploads that stay: a new upload can use the rest.
        [$pending, $count] = $this->purge();
        if (0 !== $this->maxSize && $pending + $this->cost($info['size']) > $this->maxSize) {
            throw new \OverflowException(\sprintf('The uploads of the server have a maximum of %d bytes together (the phem.upload_dir_max_size option). Try again later.', $this->maxSize));
        }

        if (0 !== $this->maxCount && $count >= $this->maxCount) {
            throw new \OverflowException(\sprintf('The server has a maximum of %d uploads (the phem.upload_dir_max_files option). Try again later.', $this->maxCount));
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('Cannot create the upload directory "%s".', $this->dir));
        }

        $id = bin2hex(random_bytes(16));
        file_put_contents($this->path($id), '');
        file_put_contents($this->dir.'/'.$id.'.json', json_encode($info, \JSON_THROW_ON_ERROR));

        return [$id, 0];
    }

    /**
     * Adds a chunk at the end of the upload.
     *
     * @param resource $chunk the bytes of the chunk
     *
     * @return int the bytes that the store has now
     *
     * @throws UploadOffsetException     when the chunk does not start at the end of the upload
     * @throws \InvalidArgumentException for an upload that is not there, or a chunk over the declared size
     */
    public function append(string $id, int $offset, $chunk): int
    {
        $info = $this->info($id);
        if (null === $info) {
            throw new \InvalidArgumentException('The upload is not there: it ended, or it is too old.');
        }

        $handle = fopen($this->path($id), 'r+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the upload.');
        }

        // One chunk at a time: two requests with the same chunk do not add it two times.
        flock($handle, \LOCK_EX);

        try {
            $size = max(0, (int) (fstat($handle)['size'] ?? 0));
            if ($offset !== $size) {
                throw new UploadOffsetException($size);
            }

            fseek($handle, $size);
            // One byte more than the rest finds a chunk that is too large.
            $copied = (int) stream_copy_to_stream($chunk, $handle, $info['size'] - $size + 1);
            if ($size + $copied > $info['size']) {
                ftruncate($handle, $size);

                throw new \InvalidArgumentException(\sprintf('The upload has a size of %d bytes: the chunk is too large.', $info['size']));
            }

            return $size + $copied;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * What the browser declared for the upload, or null for an id that is not an upload.
     *
     * @return array{name: string, type: string, size: int, fingerprint: string|null}|null
     */
    public function info(string $id): ?array
    {
        if (1 !== preg_match(self::ID, $id) || !is_file($this->path($id))) {
            return null;
        }

        $info = json_decode((string) @file_get_contents($this->dir.'/'.$id.'.json'), true);
        if (!\is_array($info) || !\is_string($info['name'] ?? null) || !\is_string($info['type'] ?? null) || !\is_int($info['size'] ?? null)) {
            return null;
        }

        return ['name' => $info['name'], 'type' => $info['type'], 'size' => $info['size'], 'fingerprint' => \is_string($info['fingerprint'] ?? null) ? $info['fingerprint'] : null];
    }

    /**
     * An empty file for the event of an empty upload (see ChunkedUploadMode). remove() removes it
     * after the event, and purge() after IDLE_AGE seconds when the event did not end.
     */
    public function empty(string $id, string $name, string $type): UploadedFile
    {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException('An upload id has 32 hex characters.');
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('Cannot create the upload directory "%s".', $this->dir));
        }

        file_put_contents($this->path($id), '');
        // With its info, purge() removes it after IDLE_AGE seconds when its event did not end.
        file_put_contents($this->dir.'/'.$id.'.json', json_encode(['name' => $name, 'type' => $type, 'size' => 0, 'fingerprint' => null], \JSON_THROW_ON_ERROR));

        // `test: true`: the file did not come from a PHP upload, but move() and isValid() work.
        return new UploadedFile($this->path($id), $name, '' !== $type ? $type : null, \UPLOAD_ERR_OK, true);
    }

    /** The file with the bytes of the upload. */
    public function path(string $id): string
    {
        return $this->dir.'/'.$id.'.part';
    }

    /** Removes the upload, after its event. A handler that moved the file keeps it. */
    public function remove(string $id): void
    {
        if (1 !== preg_match(self::ID, $id)) {
            return;
        }

        @unlink($this->path($id));
        @unlink($this->dir.'/'.$id.'.json');
    }

    /**
     * Removes the uploads with no new chunk for MAX_AGE seconds, and the uploads with no chunk at
     * all for IDLE_AGE seconds.
     *
     * @return array{int, int} the sizes (see cost()) and the number of the other uploads
     */
    private function purge(): array
    {
        $now = time();
        $pending = 0;
        $count = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $meta) {
            $id = basename($meta, '.json');
            $part = $this->path($id);
            clearstatcache(true, $part);
            $empty = !is_file($part) || 0 === filesize($part);
            $changed = is_file($part) ? (int) filemtime($part) : (int) filemtime($meta);
            if ($changed < $now - ($empty ? self::IDLE_AGE : self::MAX_AGE)) {
                $this->remove($id);

                continue;
            }

            $pending += $this->cost($this->info($id)['size'] ?? 0);
            ++$count;
        }

        return [$pending, $count];
    }

    /** The size that an upload counts in the maximum size: at least the minimum size. */
    private function cost(int $size): int
    {
        return max($size, $this->minSize);
    }
}
