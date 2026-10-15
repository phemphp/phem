<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * Serves files from mounted directories for GET and HEAD requests.
 *
 * It returns null when no real file matches, so the request goes on to the RequestHandler.
 * It does not serve PHP files from the document root (the `/` mount, which has the front
 * controller). Other mounts serve them as text.
 */
final class StaticFiles
{
    /** @var array<string, string> url prefix => directory, longest prefix first */
    private array $mounts;

    /**
     * @param array<string, string> $mounts url prefix => directory
     */
    public function __construct(array $mounts)
    {
        uksort($mounts, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $this->mounts = $mounts;
    }

    public function serve(Request $request): ?Response
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return null;
        }

        foreach ($this->mounts as $prefix => $directory) {
            $relative = $this->relativePath((string) $prefix, $request->path);

            if ($relative === null) {
                continue;
            }

            $file = $this->resolve($directory, $relative);

            if ($file !== null && rtrim((string) $prefix, '/') === '' && str_ends_with(strtolower($file), '.php')) {
                return null;
            }

            if ($file !== null) {
                return $this->respond($file, $request);
            }
        }

        return null;
    }

    /**
     * Returns the part of the path below the mount prefix (it starts with "/"), or null when the prefix does not match.
     */
    private function relativePath(string $prefix, string $path): ?string
    {
        $prefix = rtrim($prefix, '/');

        if ($prefix === '') {
            return $path;
        }

        if ($path === $prefix) {
            return '/';
        }

        return str_starts_with($path, $prefix.'/') ? substr($path, \strlen($prefix)) : null;
    }

    private function resolve(string $directory, string $relative): ?string
    {
        $root = realpath($directory);

        if ($root === false || !is_dir($root) || str_contains($relative, "\0")) {
            return null;
        }

        // Hidden files (.env, .git) are never public, except /.well-known/.
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.') && $segment !== '.well-known') {
                return null;
            }
        }

        $file = realpath($root.$relative);

        // realpath() resolves "..", "." and symlinks, so this check also blocks a symlink that leaves the root.
        if ($file === false || ($file !== $root && !str_starts_with($file, rtrim($root, '/').'/'))) {
            return null;
        }

        if (is_dir($file)) {
            $file .= '/index.html';
        }

        return is_file($file) && is_readable($file) ? $file : null;
    }

    private function respond(string $file, Request $request): Response
    {
        clearstatcache(true, $file);
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $etag = \sprintf('"%x-%x"', $mtime, $size);

        $headers = [
            'Content-Type' => MimeTypes::forPath($file),
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            'Cache-Control' => 'no-cache',
        ];

        if ($this->matchesEtag($request->header('if-none-match'), $etag)) {
            return new Response(304, $headers);
        }

        // HEAD reads the file too: the serializer needs its length for Content-Length.
        $body = file_get_contents($file);

        if ($body === false) {
            throw new \RuntimeException(\sprintf('Cannot read static file "%s".', $file));
        }

        return new Response(200, $headers, $body);
    }

    private function matchesEtag(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null) {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
