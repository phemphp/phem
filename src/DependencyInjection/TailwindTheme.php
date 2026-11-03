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

namespace Phem\DependencyInjection;

/**
 * The custom names of the `@theme` blocks of a Tailwind CSS v4 entry file, by theme key of
 * tailwind-merge-php (`--text-caption: 0.875rem;` gives `text: [caption]`). The bundle reads them
 * when the container builds (`phem.class_merger.tailwind.css`).
 *
 * It also reads the files of the relative imports (`@import "./theme.css";`). A sub-property
 * (`--text-caption--line-height`) is not a name. The `color` and `font` keys of tailwind-merge
 * already take any name, so the reader does not collect them.
 */
final class TailwindTheme
{
    /**
     * The theme keys of tailwind-merge-php. They are also the namespaces of the theme variables
     * of Tailwind CSS v4 (`--text-*`, `--font-weight-*`).
     */
    public const KEYS = ['animate', 'aspect', 'blur', 'breakpoint', 'color', 'container', 'drop-shadow', 'ease', 'font', 'font-weight', 'inset-shadow', 'leading', 'perspective', 'radius', 'shadow', 'spacing', 'text', 'text-shadow', 'tracking'];

    /** The keys of tailwind-merge that take any name. */
    private const ANY_NAME = ['color', 'font'];

    /**
     * @param array<string, list<string>> $names the names by theme key
     * @param list<string>                $files the CSS files that the reader opened
     */
    private function __construct(
        public readonly array $names,
        public readonly array $files,
    ) {
    }

    public static function fromCss(string $file): self
    {
        $names = [];
        $files = [];
        self::read($file, $names, $files);

        return new self(array_map(array_keys(...), $names), array_keys($files));
    }

    /**
     * @param array<string, array<string, true>> $names
     * @param array<string, true>                $files
     */
    private static function read(string $file, array &$names, array &$files): void
    {
        $path = realpath($file);
        if (false === $path || !is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('The CSS file "%s" does not exist.', $file));
        }

        if (isset($files[$path])) {
            return;
        }

        $files[$path] = true;
        $css = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($path)) ?? '';
        preg_match_all('/@theme\b[^{;]*(\{(?:[^{}]++|(?1))*\})/', $css, $blocks);

        foreach ($blocks[1] as $block) {
            // The variables of the block itself, not of a nested block (`@keyframes`).
            $body = preg_replace('/[^;{}]*(\{(?:[^{}]++|(?1))*\})/', '', substr($block, 1, -1)) ?? '';
            preg_match_all('/--([\w-]+)\s*:/', $body, $variables);

            foreach ($variables[1] as $variable) {
                $split = self::split($variable);
                if (null !== $split) {
                    $names[$split[0]][$split[1]] = true;
                }
            }
        }

        preg_match_all('/@import\s+["\'](\.\.?\/[^"\']+)["\']/', $css, $imports);

        foreach ($imports[1] as $import) {
            $imported = \dirname($path).'/'.$import;
            if (!is_file($imported)) {
                throw new \InvalidArgumentException(\sprintf('The CSS file "%s" imports "%s", which does not exist.', $path, $import));
            }

            self::read($imported, $names, $files);
        }
    }

    /**
     * @return array{string, string}|null the theme key and the name
     */
    private static function split(string $variable): ?array
    {
        $key = null;
        foreach (self::KEYS as $candidate) {
            // The longest key wins: `--text-shadow-glow` is a text shadow, not a text size.
            if (str_starts_with($variable, $candidate.'-') && \strlen($candidate) > \strlen($key ?? '')) {
                $key = $candidate;
            }
        }

        if (null === $key || \in_array($key, self::ANY_NAME, true)) {
            return null;
        }

        $name = substr($variable, \strlen($key) + 1);
        if ('' === $name || str_contains($name, '--')) {
            return null;
        }

        return [$key, $name];
    }
}
