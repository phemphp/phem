<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * Maps file extensions to Content-Type values for static files.
 */
final class MimeTypes
{
    public const string DEFAULT = 'application/octet-stream';

    private const array TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=UTF-8',
        // .rhp and .php sources, so the docs site can show the examples (never run).
        'rhp' => 'text/plain; charset=UTF-8',
        'php' => 'text/plain; charset=UTF-8',
    ];

    public static function forPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? self::DEFAULT;
    }
}
