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

namespace Phem\Test\Internal;

use Phem\Upload\ChunkedUploadMode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The uploads of a test event, as the `phem` Stimulus controller sends them before the event: the
 * files of an input with `data-phem-upload` go to Phem\Upload\UploadController, in chunks for
 * `chunked`. For `direct`, the test gets the URL of the storage, but it sends no file there: the
 * handler gets the DirectFile.
 *
 * @internal
 */
final class TestUploads
{
    /**
     * The files with a mode: [field name, path, mode] triples.
     *
     * @param list<array{0: string, 1: string, 2?: string|null}> $files
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function of(array $files): array
    {
        return array_values(array_filter($files, static fn (array $f): bool => null !== ($f[2] ?? null)));
    }

    /**
     * The files with no mode, for the multipart parts.
     *
     * @param list<array{0: string, 1: string, 2?: string|null}> $files
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function others(array $files): array
    {
        return array_values(array_map(static fn (array $f): array => [$f[0], $f[1]], array_filter($files, static fn (array $f): bool => null === ($f[2] ?? null))));
    }

    /**
     * Uploads the files: the `_phem_uploads[]` parts of the event, or the status of the upload
     * that the server refused.
     *
     * @param list<array{0: string, 1: string, 2: string}> $files
     * @param \Closure(Request): Response                  $send   sends a request of an upload to the server
     * @param array<string, string>                        $server the server values of the requests
     *
     * @return list<string>|int
     */
    public static function send(array $files, \Closure $send, string $url = '/', array $server = []): array|int
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'] + $server;
        $parts = [];
        foreach ($files as [$field, $path, $mode]) {
            $bytes = (string) file_get_contents($path);
            $start = json_encode(['mode' => $mode, 'name' => basename($path), 'type' => '', 'size' => \strlen($bytes), 'fingerprint' => hash('sha256', $bytes), 'resume' => null], \JSON_THROW_ON_ERROR);
            $response = $send(Request::create($url, 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PHEM_UPLOAD' => 'start'] + $server, content: $start));
            $data = self::answer($response);
            if (\is_int($data)) {
                return $data;
            }

            while (!\is_string($data['token'] ?? null)) {
                $offset = (int) ($data['offset'] ?? 0);
                $chunk = substr($bytes, $offset, max(1, (int) ($data['chunkSize'] ?? 1)));
                $headers = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PHEM_UPLOAD' => (string) $data['id'], 'HTTP_'.strtoupper(str_replace('-', '_', ChunkedUploadMode::OFFSET_HEADER)) => (string) $offset];
                $next = self::answer($send(Request::create($url, 'POST', server: $headers + $server, content: $chunk)));
                if (\is_int($next)) {
                    return $next;
                }

                $data = ['id' => $data['id'], 'chunkSize' => $data['chunkSize']] + $next;
            }

            $parts[] = json_encode(['field' => $field, 'token' => $data['token']], \JSON_THROW_ON_ERROR);
        }

        return $parts;
    }

    /** @return array<string, mixed>|int the JSON of an answer, or its status */
    private static function answer(Response $response): array|int
    {
        if (200 !== $response->getStatusCode()) {
            return $response->getStatusCode();
        }

        $data = json_decode((string) $response->getContent(), true);

        return \is_array($data) ? $data : 500;
    }
}
