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

namespace Phem\Rendering;

/**
 * Finds the root element of the HTML of a fiber: the HTML is one element, with only whitespace
 * around it. The fiber id goes on this element (see Fiber::wrapped()), so a component in a
 * `<table>`, a `<tr>` or a `<select>` has no `<phem-c>`.
 *
 * The HTML comes from the compiled markup, so its tags are balanced. The scan counts the open and
 * the close tags, and skips the comments, the attribute values and the raw text of a `<script>`, a
 * `<style>`, a `<textarea>` and a `<title>`.
 *
 * @internal
 */
final class RootTag
{
    private const VOID = ['area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true, 'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true, 'source' => true, 'track' => true, 'wbr' => true];
    private const RAW_TEXT = ['script' => true, 'style' => true, 'textarea' => true, 'title' => true];
    private const WHITESPACE = " \t\n\r\f";

    /**
     * The root element of the HTML, or null when the HTML is not one element or when its root is
     * another fiber (a `<phem-c>`, an element with a fiber id, or a placeholder).
     *
     * The scan reads `$own` when it is given: the same HTML with a short placeholder for each child
     * fiber (see Fiber::$ownHtml). The HTML of a fiber contains the HTML of all its subtree, so a
     * scan of it for each fiber reads a deep tree again for each ancestor. The open tag of the root
     * element comes before the children, so the end of its attributes is the same in the two texts.
     *
     * @return array{0: string, 1: int}|null the element with no whitespace around it, and the end of the attributes of its open tag
     */
    public static function find(string $html, ?string $own = null): ?array
    {
        $element = trim($own ?? $html, self::WHITESPACE);
        if (1 !== preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $element, $m)) {
            return null;
        }

        if ('phem-c' === strtolower($m[1])) {
            return null;
        }

        $attributesEnd = 0;
        $length = \strlen($element);
        $depth = 0;
        $offset = 0;

        while (true) {
            $tag = self::tag($element, $offset);
            if (null === $tag) {
                return null;
            }

            [$tagName, $close, $end, $selfClosing] = $tag;

            if (0 === $offset) {
                if (1 === preg_match('/\sdata-phem-(?:c|keep)[\s=>\/]/', substr($element, 0, $end))) {
                    return null;
                }

                // Before the `>` or the `/>`, after the attributes.
                $attributesEnd = $selfClosing ? $end - 2 : $end - 1;
                while (ctype_space($element[$attributesEnd - 1])) {
                    --$attributesEnd;
                }
            }

            if ($close) {
                --$depth;
            } elseif (!$selfClosing && !isset(self::VOID[$tagName])) {
                ++$depth;

                if (isset(self::RAW_TEXT[$tagName])) {
                    $end = stripos($element, '</'.$tagName, $end);
                    if (false === $end) {
                        return null;
                    }

                    $offset = $end;

                    continue;
                }
            }

            if (0 === $depth) {
                return $end === $length ? [null !== $own ? trim($html, self::WHITESPACE) : $element, $attributesEnd] : null;
            }

            $offset = self::next($element, $end);
            if (null === $offset) {
                return null;
            }
        }
    }

    /**
     * The tag at an offset: its lowercase name, whether it is a close tag, the offset after its
     * `>`, and whether it ends with `/>`.
     *
     * @return array{0: string, 1: bool, 2: int, 3: bool}|null
     */
    private static function tag(string $html, int $offset): ?array
    {
        if (1 !== preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m, 0, $offset)) {
            return null;
        }

        // The attributes: a quoted value can have a `>`.
        if (1 !== preg_match('/\G(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>/', $html, $a, 0, $offset + \strlen($m[0]))) {
            return null;
        }

        $end = $offset + \strlen($m[0]) + \strlen($a[0]);

        return [strtolower($m[2]), '/' === $m[1], $end, '/' === $html[$end - 2]];
    }

    /** The offset of the next tag after an offset, after the comments and the text. */
    private static function next(string $html, int $offset): ?int
    {
        while (false !== $offset = strpos($html, '<', $offset)) {
            if (str_starts_with(substr($html, $offset, 4), '<!--')) {
                $end = strpos($html, '-->', $offset + 4);
                if (false === $end) {
                    return null;
                }

                $offset = $end + 3;

                continue;
            }

            if (1 === preg_match('/\G<\/?[a-zA-Z]/', $html, $m, 0, $offset)) {
                return $offset;
            }

            // A `<` of the text, or a `<!…>` or a `<?…>`.
            ++$offset;
        }

        return null;
    }
}
