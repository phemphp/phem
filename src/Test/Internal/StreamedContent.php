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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The content of a response, also of a streamed response: its chunks go to the content, not to
 * the output (the response flushes each chunk).
 *
 * @internal
 */
final class StreamedContent
{
    /**
     * @param (\Closure(string): void)|null $chunk gets each chunk of a streamed response
     */
    public static function of(Response $response, ?\Closure $chunk = null): string
    {
        if (!$response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        $content = '';
        ob_start(static function (string $data) use (&$content, $chunk): string {
            if ('' !== $data) {
                $content .= $data;
                $chunk?->__invoke($data);
            }

            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
        }

        return $content;
    }
}
