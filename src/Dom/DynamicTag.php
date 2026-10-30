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

namespace Phem\Dom;

use Phem\Rendering\RootTag;
use Twig\Markup;

/**
 * The checked tag of a `<Dynamic>` element (see Phem\Dynamic). phemc checks it once in a
 * variable, and uses the variable for the open tag and for the end tag:
 *
 *     {% set _phem_tag_0 = phem_tag(tag) %}<{{ _phem_tag_0 }} ...>{{ phem_end_tag(_phem_tag_0, content) }}
 *
 * The tag can come from data, so it is checked: a valid name (see TagName), and not an element
 * of self::UNSAFE.
 */
final class DynamicTag implements \Stringable
{
    /**
     * The elements that a tag from a value cannot make: the text of `<script>` and `<style>` runs
     * or applies with no escape, and the attributes of the others load a document or a resource
     * (`<iframe srcdoc>`, `<object data>`, `<base href>`), also from a spread.
     */
    private const UNSAFE = ['base' => true, 'embed' => true, 'frame' => true, 'frameset' => true, 'iframe' => true, 'link' => true, 'meta' => true, 'object' => true, 'script' => true, 'style' => true];

    private function __construct(
        public readonly string $name,
        private readonly bool $void,
    ) {
    }

    /** The checked tag: `phem_tag(tag)`. */
    public static function of(mixed $tag): self
    {
        if (!TagName::isValid($tag)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name for <Dynamic>.', \is_string($tag) ? $tag : get_debug_type($tag)));
        }

        $lower = strtolower($tag);
        if (isset(self::UNSAFE[$lower])) {
            throw new \InvalidArgumentException(\sprintf('<Dynamic> cannot render a <%s>: write it as static markup.', $lower));
        }

        return new self($tag, isset(RootTag::VOID[$lower]));
    }

    /**
     * The content and the end tag: `phem_end_tag(tag, content)`. A void element has no end tag,
     * and its content must be empty.
     */
    public static function close(self $tag, mixed $content = ''): Markup
    {
        $content = (string) $content;

        if ($tag->void) {
            if ('' !== trim($content)) {
                throw new \InvalidArgumentException(\sprintf('<%s> is a void element: <Dynamic tag="%1$s"> cannot have children.', strtolower($tag->name)));
            }

            return new Markup('', 'UTF-8');
        }

        return new Markup($content.'</'.$tag->name.'>', 'UTF-8');
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
