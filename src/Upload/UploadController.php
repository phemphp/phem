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

use Phem\Http\SameOrigin;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Takes the uploads of the files of an input with `data-phem-upload`, before the event of the
 * files. The requests go to the URL of the page, as the events (see
 * Phem\Controller\EventController), so the access rules of the page apply to them. Each request
 * has the `X-Phem-Upload` header:
 *
 * - `X-Phem-Upload: start` with the JSON `{mode, name, type, size, …}` starts an upload in the
 *   UploadMode of its name (see ChunkedUploadMode and DirectUploadMode).
 * - `X-Phem-Upload: <id>` adds a chunk to a chunked upload (see ChunkedUploadMode::chunk()).
 *
 * When the server has the file, the answer has a `token` (see UploadToken). The event sends the
 * token in the place of the file (see UploadResolver).
 *
 * Against CSRF, the request has the `X-Phem-Upload` header and comes from the same origin.
 * Against a denial of service, a file has a maximum size (`phem.max_upload_size`), and so do a
 * chunk (`phem.upload_chunk_size`) and the uploads of the server together
 * (`phem.upload_dir_max_size`).
 */
final class UploadController
{
    /** The header of an upload request. */
    public const HEADER = 'X-Phem-Upload';

    /** The longest name or media type of a file. */
    private const MAX_NAME = 255;

    /**
     * @param ContainerInterface $modes the UploadMode services by their name: only the mode of a request is built
     */
    public function __construct(
        private readonly ContainerInterface $modes,
        private readonly int $maxUploadSize = 8388608,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!SameOrigin::check($request)) {
            throw new AccessDeniedHttpException('An upload must come from the same origin.');
        }

        $step = (string) $request->headers->get(self::HEADER);
        if ('start' === $step) {
            return self::json($this->start($request));
        }

        $chunks = $this->modes->has('chunked') ? $this->modes->get('chunked') : null;
        if (!$chunks instanceof ChunkedUploadMode) {
            throw new NotFoundHttpException('The upload is not there.');
        }

        return $chunks->chunk($step, $request);
    }

    /**
     * A JSON answer that no cache keeps.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private function start(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('An upload starts with the JSON {mode, name, type, size}.');
        }

        $name = $data['name'] ?? null;
        $type = $data['type'] ?? '';
        $size = $data['size'] ?? null;
        if (!\is_string($name) || '' === $name || \strlen($name) > self::MAX_NAME || !\is_string($type) || \strlen($type) > self::MAX_NAME || !\is_int($size) || $size < 0) {
            throw new BadRequestHttpException('An upload starts with the JSON {mode, name, type, size}.');
        }

        if (0 !== $this->maxUploadSize && $size > $this->maxUploadSize) {
            throw new HttpException(413, \sprintf('A file can have a maximum of %d bytes (the phem.max_upload_size option).', $this->maxUploadSize));
        }

        $mode = \is_string($data['mode'] ?? null) && $this->modes->has($data['mode']) ? $this->modes->get($data['mode']) : null;
        if (!$mode instanceof UploadMode) {
            throw new BadRequestHttpException('The mode of an upload is not valid: see the data-phem-upload attribute.');
        }

        return $mode->start($name, $type, $size, $data);
    }
}
