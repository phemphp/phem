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

use Phem\Attribute\ErrorPage;
use Phem\Manifest\Manifest;

/**
 * Chooses the error page of a failed request: a function with `#[ErrorPage]` (see
 * Phem\Attribute\ErrorPage). The page of the longest path that matches the request path wins,
 * then a page with the status wins over a page for all the statuses:
 *
 *     #[ErrorPage(404)]               // 404 at /missing
 *     #[ErrorPage]                    // 500 at /cart
 *     #[ErrorPage(path: '/admin')]    // 404 and 500 at /admin/users
 *
 * It reads the attributes of the error pages of the manifest once. An attribute must have a
 * status from 400 to 599, a path that starts with `/`, and no other page for its status at its
 * path. The choice skips an attribute that is not valid, so the other pages still show, and
 * check() reports it: ErrorPagesWarmer calls it when the container builds.
 */
final class ErrorPages
{
    /** @var list<array{page: string, status: list<int>|null, path: string}>|null */
    private ?array $pages = null;
    /** @var list<string> */
    private array $errors = [];

    public function __construct(private readonly Manifest $manifest)
    {
    }

    /** The error page of a status at a request path, or null when no page matches it. */
    public function find(int $status, string $path): ?string
    {
        $found = null;
        $rank = null;
        foreach ($this->pages() as $page) {
            if (!self::within($path, $page['path']) || (null !== $page['status'] && !\in_array($status, $page['status'], true))) {
                continue;
            }

            // The longest path first, then the page with the status.
            $score = [\strlen($page['path']), null !== $page['status']];
            if (null === $rank || $score > $rank) {
                [$found, $rank] = [$page['page'], $score];
            }
        }

        return $found;
    }

    /**
     * Checks the `#[ErrorPage]` attributes of the error pages.
     *
     * @throws \LogicException with all the errors, one on each line
     */
    public function check(): void
    {
        $this->pages();
        if ([] !== $this->errors) {
            throw new \LogicException(implode("\n", $this->errors));
        }
    }

    /** Whether the request path is in the section of an error page: `/admin` or `/admin/...`. */
    private static function within(string $path, string $section): bool
    {
        return '/' === $section || $path === $section || str_starts_with($path, $section.'/');
    }

    /** @return list<array{page: string, status: list<int>|null, path: string}> */
    private function pages(): array
    {
        if (null !== $this->pages) {
            return $this->pages;
        }

        $pages = [];
        $taken = [];
        foreach ($this->manifest->errorPages() as $page) {
            if (!\function_exists($page)) {
                $this->errors[] = \sprintf('The error page "%s" is not loaded. Run `phemc build` again.', $page);
                continue;
            }

            foreach ((new \ReflectionFunction($page))->getAttributes(ErrorPage::class) as $attribute) {
                // An unknown argument (`paths:`) or a wrong type is an \Error of PHP.
                try {
                    $entry = self::entry($page, $attribute->newInstance());
                } catch (\LogicException|\Error $e) {
                    $this->errors[] = \sprintf('The #[ErrorPage] of "%s" is not valid: %s', $page, $e->getMessage());
                    continue;
                }

                // path and status => what the page is for
                $keys = [];
                foreach ($entry['status'] ?? [null] as $status) {
                    $keys[$entry['path'].' '.($status ?? 'all')] = null === $status ? 'all the statuses' : 'the status '.$status;
                }

                $same = array_key_first(array_intersect_key($keys, $taken));
                if (null !== $same) {
                    $this->errors[] = \sprintf('"%s" and "%s" are both the error page of %s at "%s". Remove one #[ErrorPage], or give it another status or path.', $taken[$same], $page, $keys[$same], $entry['path']);
                    continue;
                }

                $taken += array_fill_keys(array_keys($keys), $page);
                $pages[] = $entry;
            }
        }

        return $this->pages = $pages;
    }

    /** @return array{page: string, status: list<int>|null, path: string} */
    private static function entry(string $page, ErrorPage $attribute): array
    {
        /** @var array<mixed>|null $codes PHP does not check the items of the list */
        $codes = null === $attribute->status ? null : (array) $attribute->status;

        $status = null === $codes ? null : [];
        foreach ($codes ?? [] as $code) {
            if (!\is_int($code) || $code < 400 || $code > 599) {
                throw new \LogicException(\sprintf('the status %s is not an error status (400 to 599).', \is_int($code) ? (string) $code : get_debug_type($code)));
            }

            $status[] = $code;
        }

        if (!str_starts_with($attribute->path, '/')) {
            throw new \LogicException(\sprintf('the path "%s" does not start with "/".', $attribute->path));
        }

        return ['page' => $page, 'status' => $status, 'path' => '/' === $attribute->path ? '/' : rtrim($attribute->path, '/')];
    }
}
