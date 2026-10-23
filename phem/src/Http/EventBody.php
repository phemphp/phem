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

namespace Phem\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Reads the JSON of an event: the body, or the `_phem` part of a multipart body (an event with
 * files).
 *
 * The browser compresses a large JSON body with gzip (`Content-Encoding: gzip`), because it
 * keeps all the state of the page. The JSON has a maximum size (`phem.max_event_size`), for the
 * compressed body and for the JSON after the server unzips it. The server unzips in small parts
 * and stops at the limit, so a small body that unzips to a very large one (a "zip bomb") does
 * not use the memory.
 */
final class EventBody
{
    /** The part of a multipart event with the JSON. */
    public const PART = '_phem';

    /** The compressed bytes that the server unzips at a time: the output of one part is at most about 1 MiB. */
    private const CHUNK = 1024;

    /** @param int $maxSize the `phem.max_event_size` option: the maximum bytes of the JSON, 0 for no limit */
    public function __construct(private readonly int $maxSize = 1048576)
    {
    }

    /**
     * @throws HttpException 413 when the JSON is too large, 415 for another content encoding, and
     *                       400 for a gzip body that is not valid
     */
    public function json(Request $request): string
    {
        $encoding = strtolower(trim((string) $request->headers->get('Content-Encoding', 'identity')));
        if ('identity' !== $encoding && ('gzip' !== $encoding || self::isMultipart($request))) {
            throw new UnsupportedMediaTypeHttpException('An event body has no content encoding, or gzip (a body with no files).');
        }

        if (self::isMultipart($request)) {
            // PHP read the body already: the limit is for the JSON part.
            $json = $request->request->all()[self::PART] ?? '';

            return $this->limit(\is_string($json) ? $json : '');
        }

        $length = $request->headers->get('Content-Length');
        if (0 !== $this->maxSize && null !== $length && ctype_digit($length) && (int) $length > $this->maxSize) {
            // Before the server reads the body.
            throw $this->tooLarge();
        }

        $body = $this->limit($request->getContent());

        return 'gzip' === $encoding ? $this->gunzip($body) : $body;
    }

    public static function isMultipart(Request $request): bool
    {
        return 'multipart/form-data' === strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));
    }

    private function gunzip(string $body): string
    {
        $context = inflate_init(\ZLIB_ENCODING_GZIP) ?: throw new \LogicException('zlib cannot start a gzip stream.');
        $json = '';
        foreach (str_split($body, self::CHUNK) as $chunk) {
            $part = @inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if (false === $part) {
                throw new BadRequestHttpException('The gzip body of the event is not valid.');
            }

            $json .= $part;
            $this->limit($json);
        }

        if (\ZLIB_STREAM_END !== inflate_get_status($context)) {
            throw new BadRequestHttpException('The gzip body of the event is not complete.');
        }

        return $json;
    }

    private function limit(string $json): string
    {
        if (0 !== $this->maxSize && \strlen($json) > $this->maxSize) {
            throw $this->tooLarge();
        }

        return $json;
    }

    private function tooLarge(): HttpException
    {
        return new HttpException(413, \sprintf('An event body can have a maximum of %d bytes (the phem.max_event_size option).', $this->maxSize));
    }
}
